<?php

namespace StrontiumCorp\LaravelMfa;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Container\Container as LiveContainer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\Factor;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Contracts\PasswordConfirmationPolicy;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\TrustedBrowserRevocation;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Events\ImpersonationGranted;
use StrontiumCorp\LaravelMfa\Events\VerificationsRevoked;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Exceptions\ImpersonationNotAllowed;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Policies\EnforceForRoles;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;
use StrontiumCorp\LaravelMfa\Support\ChallengeSteps;
use StrontiumCorp\LaravelMfa\Support\EnrollmentLinks;
use StrontiumCorp\LaravelMfa\Support\MfaContext;
use StrontiumCorp\LaravelMfa\Support\Nudge;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use StrontiumCorp\LaravelMfa\Support\Revocations;
use StrontiumCorp\LaravelMfa\Support\SessionIdentity;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use StrontiumCorp\LaravelMfa\Support\VerificationLifetime;
use StrontiumCorp\LaravelMfa\Testing\FakeSmsSender;
use StrontiumCorp\LaravelMfa\Testing\FixedCodeGenerator;
use Throwable;

/**
 * @phpstan-import-type ReverifyReminder from MfaContext
 *
 * Facade root (StrontiumCorp\LaravelMfa\Facades\Mfa).
 *
 * Holds no per-request state — the session/request are always passed in —
 * so it is safe as a singleton under Octane. Code-level rules are classes
 * named in config (enforcement.policy, routes.password_confirmation_policy),
 * resolved per call.
 */
class Mfa
{
    public const SESSION_PREFIX = 'mfa.verified';

    /** Set on a verified session whose user still lacks a required factor type. */
    public const ENROLL_PREFIX = 'mfa.enroll';

    /** Session: when each guard's user logged in (unix seconds), for Mfa::reset()'s logout. */
    public const LOGIN_AT_PREFIX = 'mfa.login_at';

    /** Session: who granted an impersonation (grantForImpersonation()), per guard and target. */
    public const IMPERSONATOR_PREFIX = 'mfa.impersonator';

    /** Session: "Later" on the "check coming up" reminder, until the next verification. */
    public const REMINDER_DISMISSED = 'mfa.reminder_dismissed';

    /** When the password was last confirmed: Laravel's own key, shared with password.confirm. */
    public const PASSWORD_CONFIRMED_AT = 'auth.password_confirmed_at';

    /** Request attribute holding a user's cached confirmed types for the rest of the request. */
    private const MEMO_PREFIX = 'mfa.factor-types.';

    /** Set to false (Mfa::ignoreMigrations()) if you publish and own the migrations. */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        static::$runsMigrations = false;
    }

    /**
     * The user model MFA rows belong to (user_id foreign keys):
     * config('mfa.user_model'), else the model of the first MFA guard.
     *
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        $guard = ((array) config('mfa.guards'))[0] ?? 'web';

        /** @var class-string<Model> */
        return config('mfa.user_model')
            ?? config('auth.providers.'.config("auth.guards.{$guard}.provider").'.model')
            ?? 'App\\Models\\User';
    }

    public function __construct(
        private readonly Container $app,
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly Dispatcher $events,
    ) {}

    public function enabled(): bool
    {
        // Equivalent mutant(s): the bool return type coerces.
        return (bool) $this->config->get('mfa.enabled'); // @pest-mutate-ignore: RemoveBooleanCast
    }

    /** @return list<FactorType> */
    public function enabledTypes(): array
    {
        return array_values(array_filter(
            FactorType::cases(),
            fn (FactorType $type): bool => $this->isTypeEnabled($type),
        ));
    }

    public function isTypeEnabled(FactorType $type): bool
    {
        // Equivalent mutant(s): the bool return type coerces; the deep config merge guarantees the key exists for every built-in type.
        return (bool) $this->config->get("mfa.factors.{$type->value}.enabled", false); // @pest-mutate-ignore: RemoveBooleanCast,FalseToTrue
    }

    /** Shown first, with a "Recommended" badge, when users add a method (factors.{type}.recommended). */
    public function isTypeRecommended(FactorType $type): bool
    {
        return (bool) $this->config->get("mfa.factors.{$type->value}.recommended");
    }

    public function factor(FactorType|string $type): Factor
    {
        return $this->app->make(FactorManager::class)->factor($type);
    }

    /*
    |--------------------------------------------------------------------------
    | User state (cached — consulted on every unverified request)
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this user has a confirmed factor of an enabled type. The cache
     * holds the user's confirmed types (all of them), and the enabled types
     * are applied on every read, so turning a type off or on in config takes
     * effect at once, with no stale "has MFA" in either direction.
     */
    public function hasConfirmedFactors(MultiFactorAuthenticatable $user): bool
    {
        return $this->anyEnabled($this->cachedConfirmedTypes($user->getAuthIdentifier()));
    }

    /**
     * The user's confirmed types from the cache (filled on a miss), kept on
     * the live request for the rest of it: the gate, the shared context and
     * the nudge all ask, and the cache is read once. Only for a request with
     * a session (a long-lived queue worker's request must never keep one).
     */
    private function cachedConfirmedTypes(int|string $userId): string
    {
        $request = $this->liveRequest();
        $memo = self::MEMO_PREFIX.$userId;

        if ($request?->attributes->has($memo)) {
            return (string) $request->attributes->get($memo);
        }

        $key = $this->cacheKey($userId);
        $types = $this->cacheStore()->get($key);

        if (! is_string($types)) {
            $types = $this->queryConfirmedTypes($userId);

            // add(), not put(): if a factor changed while we were querying, its
            // model event has already written the fresh answer — never let this
            // possibly-stale read overwrite it.
            // Equivalent mutant(s): ttl is an int.
            $this->cacheStore()->add($key, $types, (int) $this->config->get('mfa.cache.ttl')); // @pest-mutate-ignore: RemoveIntegerCast
        }

        $request?->attributes->set($memo, $types);

        return $types;
    }

    public function forgetCachedState(MultiFactorAuthenticatable $user): void
    {
        $this->refreshCachedStateFor($user->getAuthIdentifier());
    }

    /**
     * Write-through from MfaFactor model events: store the fresh answer
     * rather than just forgetting the key, so a concurrent fill that read
     * the old state cannot win the race.
     */
    public function refreshCachedStateFor(int|string $userId): void
    {
        $types = $this->queryConfirmedTypes($userId);

        $this->cacheStore()->put($this->cacheKey($userId), $types, (int) $this->config->get('mfa.cache.ttl'));
        $this->liveRequest()?->attributes->set(self::MEMO_PREFIX.$userId, $types);
    }

    /**
     * The user's confirmed factor types, enabled or not, as "|email|totp|"
     * ("|" for none): a non-empty string, so it is never confused with a
     * store's false or null for a miss.
     */
    private function queryConfirmedTypes(int|string $userId): string
    {
        $types = MfaFactor::query()
            ->where('user_id', $userId)
            ->whereNotNull('confirmed_at')
            ->distinct()
            ->pluck('type')
            ->map(fn (FactorType|string $type) => $type instanceof FactorType ? $type->value : $type)
            ->sort()
            ->implode('|');

        return '|'.$types.($types === '' ? '' : '|');
    }

    /** Whether any enabled type is in a queryConfirmedTypes() string. */
    private function anyEnabled(string $types): bool
    {
        foreach ($this->enabledTypes() as $type) {
            if (str_contains($types, '|'.$type->value.'|')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this user must (still) enroll: an enforcement rule applies and
     * they lack a confirmed factor of some required type (every type in
     * enforcement.required_types that is enabled; any one enabled type when
     * it names none).
     */
    public function mustEnroll(MultiFactorAuthenticatable $user): bool
    {
        if (! $this->enforcesAnyone()) {
            return false;
        }

        if (! $this->hasConfirmedFactors($user)) {
            return $this->isEnforced($user);
        }

        $required = $this->requiredTypes();

        if ($required === [] || ! $this->isEnforced($user)) {
            return false;
        }

        return array_diff($this->typeValues($required), $this->typeValues($this->confirmedTypes($user))) !== [];
    }

    /** Whether an enforcement rule (roles or policy) applies to this user. */
    public function isEnforced(MultiFactorAuthenticatable $user): bool
    {
        [$roles, $policy] = $this->enforcementRules();

        if ($roles !== [] && (new EnforceForRoles($roles))->mustEnroll($user)) {
            return true;
        }

        /** @var EnforcementPolicy|null $instance */
        $instance = $policy === null ? null : $this->live()->make($policy);

        return $instance !== null && $instance->mustEnroll($user);
    }

    /** Whether any enforcement rule is configured at all (cheap; no user needed). */
    public function enforcesAnyone(): bool
    {
        [$roles, $policy] = $this->enforcementRules();

        return $roles !== [] || $policy !== null;
    }

    /**
     * The roles and policy class from config('mfa.enforcement'). The v0.1
     * key config('mfa.enforce') (a roles list or a class) is still honoured
     * when neither is set, so an old published config never fails open;
     * mfa:doctor asks for it to be moved.
     *
     * @return array{0: list<string>, 1: string|null}
     */
    public function enforcementRules(): array
    {
        $roles = (array) $this->config->get('mfa.enforcement.roles');
        $policy = $this->config->get('mfa.enforcement.policy');

        if ($roles === [] && ($policy === null || $policy === '')) {
            $legacy = $this->config->get('mfa.enforce');
            $roles = is_array($legacy) ? $legacy : [];
            $policy = is_string($legacy) ? $legacy : null;
        }

        return [
            array_values(array_map('strval', $roles)),
            is_string($policy) && $policy !== '' ? $policy : null,
        ];
    }

    /**
     * The enabled types listed in enforcement.required_types. Empty means any
     * enabled type satisfies enforcement.
     *
     * @return list<FactorType>
     */
    public function requiredTypes(): array
    {
        $required = array_map('strval', (array) $this->config->get('mfa.enforcement.required_types'));

        return array_values(array_filter(
            $this->enabledTypes(),
            fn (FactorType $type): bool => in_array($type->value, $required, true),
        ));
    }

    /**
     * The types this user may verify with at the challenge (see
     * challengeRequirement()).
     *
     * @return list<FactorType>
     */
    public function challengeTypes(MultiFactorAuthenticatable $user): array
    {
        return $this->challengeRequirement($user)['types'];
    }

    /**
     * What this user's challenge asks for. An enforced user who holds
     * required types must pass **every** one they hold, one code each
     * (`all`), and nothing else counts; a recovery code still passes the
     * whole challenge on its own. Everyone else, and an enforced user who
     * holds no required type yet (they verify with what they have, then must
     * enroll), passes with any one enabled type.
     *
     * @return array{types: list<FactorType>, all: bool}
     */
    public function challengeRequirement(MultiFactorAuthenticatable $user): array
    {
        $required = $this->requiredTypes();

        if ($required === [] || ! $this->enforcesAnyone() || ! $this->isEnforced($user)) {
            return ['types' => $this->enabledTypes(), 'all' => false];
        }

        $held = array_values(array_intersect($this->typeValues($required), $this->typeValues($this->confirmedTypes($user))));

        return $held === []
            ? ['types' => $this->enabledTypes(), 'all' => false]
            : ['types' => array_map(fn (string $value) => FactorType::from($value), $held), 'all' => true];
    }

    /**
     * The types this user may add on the settings page: the required types
     * for an enforced user (enforcement.required_types), every enabled type
     * otherwise. The factor routes refuse the rest.
     *
     * @return list<FactorType>
     */
    public function enrollableTypes(MultiFactorAuthenticatable $user): array
    {
        $required = $this->requiredTypes();

        return $required !== [] && $this->enforcesAnyone() && $this->isEnforced($user) ? $required : $this->enabledTypes();
    }

    /**
     * The user's confirmed factor types that are enabled (one query).
     *
     * @return list<FactorType>
     */
    public function confirmedTypes(MultiFactorAuthenticatable $user): array
    {
        $held = MfaFactor::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereNotNull('confirmed_at')
            ->distinct()
            ->pluck('type')
            ->map(fn (FactorType|string $type) => $type instanceof FactorType ? $type->value : $type)
            ->all();

        return array_values(array_filter($this->enabledTypes(), fn (FactorType $type): bool => in_array($type->value, $held, true)));
    }

    /**
     * @param  list<FactorType>  $types
     * @return list<string>
     */
    private function typeValues(array $types): array
    {
        return array_map(fn (FactorType $type) => $type->value, $types);
    }

    /*
    |--------------------------------------------------------------------------
    | Password confirmation (routes.password_confirmation)
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this user must confirm their password before factor changes:
     * routes.password_confirmation is on, they have a password (an empty
     * stored password can't be confirmed), and the policy class in
     * routes.password_confirmation_policy, if set, says so (e.g. not for
     * social-login accounts).
     */
    public function requiresPasswordConfirmation(MultiFactorAuthenticatable $user): bool
    {
        if (! $this->config->get('mfa.routes.password_confirmation') || (string) $user->getAuthPassword() === '') {
            return false;
        }

        $policy = $this->config->get('mfa.routes.password_confirmation_policy');

        /** @var PasswordConfirmationPolicy|null $instance */
        $instance = is_string($policy) && $policy !== '' ? $this->live()->make($policy) : null;

        return $instance === null || $instance->mustConfirmPassword($user);
    }

    /**
     * Whether this session confirmed the password within auth.password_timeout,
     * by MFA's prompt or the app's own password.confirm (the same key).
     */
    public function passwordRecentlyConfirmed(Session $session): bool
    {
        $confirmedAt = (int) $session->get(self::PASSWORD_CONFIRMED_AT, 0);

        return now()->getTimestamp() - $confirmedAt <= (int) $this->config->get('auth.password_timeout');
    }

    public function markPasswordConfirmed(Session $session): void
    {
        $session->put(self::PASSWORD_CONFIRMED_AT, now()->getTimestamp());
    }

    /**
     * Check the password with the user provider of the session guard this
     * user is logged in with, as Auth::validate() would.
     */
    public function validatePassword(Request $request, MultiFactorAuthenticatable $user, string $password): bool
    {
        $guard = $this->live()->make(AuthFactory::class)->guard($this->guardFor($request, $user));

        return $guard instanceof SessionGuard && $guard->getProvider()->validateCredentials($user, ['password' => $password]);
    }

    /** Whether this user would be let through without a challenge right now. */
    public function isSatisfied(Request $request, MultiFactorAuthenticatable $user): bool
    {
        return $this->isVerifiedFor($request, $user)
            || (! $this->hasConfirmedFactors($user) && ! $this->mustEnroll($user));
    }

    /*
    |--------------------------------------------------------------------------
    | Session verification
    |--------------------------------------------------------------------------
    */

    /**
     * Verification is always per guard: passing MFA as web#5 says nothing
     * about admin#5. With no guard given, the first configured guard is used.
     */
    public function isVerified(Session $session, Authenticatable $user, ?string $guard = null): bool
    {
        return $session->has($this->sessionKey($guard ?? $this->guards()[0] ?? 'web', $user->getAuthIdentifier()));
    }

    /** Verified under the guard this user is logged in with in this request. */
    public function isVerifiedFor(Request $request, Authenticatable $user): bool
    {
        return $this->isVerified($request->session(), $user, $this->guardFor($request, $user));
    }

    public function isVerifiedById(Session $session, string $guard, int|string $id): bool
    {
        return $session->has($this->sessionKey($guard, $id));
    }

    /**
     * Mark the session as verified after a successful challenge. Regenerates
     * the session id to prevent fixation.
     */
    /** @param array<string, mixed> $context */
    public function markVerified(Request $request, Authenticatable $user, ?FactorType $via = null, array $context = []): void
    {
        $guard = $this->guardFor($request, $user);

        $request->session()->put($this->sessionKey($guard, $user->getAuthIdentifier()), now()->getTimestamp());
        $this->syncEnrollmentRequirement($request->session(), $guard, $user);
        $lifetime = $user instanceof MultiFactorAuthenticatable ? $this->lifetime()->start($request->session(), $guard, $user) : null;
        // Warm the revocation lookup the gate makes on every request.
        $this->live()->make(Revocations::class)->stamps($user->getAuthIdentifier());
        // Passed MFA as themselves: no longer riding on an impersonator's grant,
        // and no half-finished challenge left over.
        $request->session()->forget([
            self::IMPERSONATOR_PREFIX.'.'.$guard.'.'.$user->getAuthIdentifier(),
            ChallengeSteps::SESSION_KEY.'.'.$guard.'.'.$user->getAuthIdentifier(),
        ]);
        // A new window: the "check coming up" reminder starts over.
        $request->session()->forget(self::REMINDER_DISMISSED);
        $request->session()->regenerate();

        // The window, for verifications that have one (mfa.lifetime).
        $window = $lifetime !== null && ($lifetime['until'] !== null || $lifetime['idle'] !== null) ? array_filter([
            'profile' => $lifetime['profile'],
            'expires_at' => $lifetime['until'] === null ? null : CarbonImmutable::createFromTimestamp($lifetime['until'])->toIso8601String(),
        ]) : [];

        $this->events->dispatch(new VerificationSucceeded($user, $via, null, $context + $window));

        RequestContext::end($request);
    }

    /**
     * Re-decide whether this verified session must enroll a required factor
     * type, after the user's factors changed (settings confirm/remove).
     */
    public function refreshEnrollmentRequirement(Request $request, MultiFactorAuthenticatable $user): void
    {
        $guard = $this->guardFor($request, $user);

        if ($this->isVerifiedById($request->session(), $guard, $user->getAuthIdentifier())) {
            $this->syncEnrollmentRequirement($request->session(), $guard, $user);
        }
    }

    /**
     * Whether this verified session is held on the enrollment pages until a
     * required factor type is added. Decided at verification and whenever
     * the factors change, so a verified request still costs no query.
     */
    public function mustEnrollAfterVerification(Session $session, string $guard, int|string $id): bool
    {
        return $session->has(self::ENROLL_PREFIX.'.'.$guard.'.'.$id);
    }

    private function syncEnrollmentRequirement(Session $session, string $guard, Authenticatable $user): void
    {
        $key = self::ENROLL_PREFIX.'.'.$guard.'.'.$user->getAuthIdentifier();

        if ($user instanceof MultiFactorAuthenticatable && $this->mustEnroll($user)) {
            $session->put($key, true);
        } else {
            $session->forget($key);
        }
    }

    /**
     * Call right after a login-swap impersonation (Auth::loginUsingId($target)).
     *
     * If the target has MFA, the impersonator must have actually passed MFA
     * in this session; having no factors is not enough (decision D9), so
     * impersonation can never step around the target's second factor.
     * Otherwise the impersonator only needs to be MFA-satisfied, and not in
     * the last minutes of their own verification (grace). The impersonation
     * gets the target's lifetime profile capped at the admin's own window,
     * ends when the admin is revoked or reset, and logs out when it ends.
     * Any password confirmation in the session is dropped: it was the
     * impersonator's.
     */
    public function grantForImpersonation(Authenticatable $impersonator, Authenticatable $target, ?Request $request = null): void
    {
        // Resolve from the live container, not the one captured when this
        // singleton was built: under Octane each request runs in a clone.
        $request ??= $this->live()->make('request');

        $allowed = $target instanceof MultiFactorAuthenticatable && $this->hasConfirmedFactors($target)
            ? $this->isVerifiedFor($request, $impersonator)
            : ! $impersonator instanceof MultiFactorAuthenticatable || $this->isSatisfied($request, $impersonator);

        $guard = $this->guardFor($request, $target);
        $session = $request->session();
        $adminId = $impersonator->getAuthIdentifier();
        // The admin's own verification, under the guard they signed in with
        // (the target's after a same-guard swap; their own with several guards).
        $adminGuard = $this->guardFor($request, $impersonator);
        $adminGuard = $this->verifiedAt($session, $adminGuard, $adminId) !== null ? $adminGuard : $guard;
        $adminVerifiedAt = $this->verifiedAt($session, $adminGuard, $adminId);
        $adminLifetime = $this->lifetime()->entry($session, $adminGuard, $adminId);

        // Not on a verification that is ending (grace included) or was revoked.
        if (! $allowed
            || ($adminLifetime !== null && $this->lifetime()->status($session, $adminGuard, $adminId, $adminLifetime) !== null)
            || ($adminVerifiedAt !== null && $this->isRevoked($adminId, $adminVerifiedAt))) {
            throw ImpersonationNotAllowed::impersonatorNotVerified();
        }

        $session->put($this->sessionKey($guard, $target->getAuthIdentifier()), now()->getTimestamp());
        // The target's profile, capped at the admin's own window; it logs out when it ends.
        if ($target instanceof MultiFactorAuthenticatable) {
            $this->lifetime()->startImpersonation($session, $guard, $target, $adminLifetime);
        }
        // Revoking the admin (or resetting them) ends it too.
        $session->put(self::IMPERSONATOR_PREFIX.'.'.$guard.'.'.$target->getAuthIdentifier(), [
            'id' => $adminId,
            'guard' => $adminGuard,
            'since' => $adminVerifiedAt ?? now()->getTimestamp(),
        ]);
        // A password confirmed by the impersonator is not the target's: it
        // must not let them change the target's factors (or pass the app's
        // own password.confirm as the target).
        $request->session()->forget(self::PASSWORD_CONFIRMED_AT);

        $this->events->dispatch(new ImpersonationGranted($target, null, null, [
            'impersonator_type' => $impersonator::class,
            'impersonator_id' => $impersonator->getAuthIdentifier(),
        ]));
    }

    /**
     * An administrator's reset: removes every factor and recovery code, and
     * logs the user out of every session on its next request (they sign in
     * again with their password, then enroll if they must). $by names who
     * asked, for the events (e.g. "console:mfa:reset").
     */
    public function reset(MultiFactorAuthenticatable $user, ?string $by = null): int
    {
        $count = 0;

        foreach ($user->mfaFactors()->get() as $factor) {
            $factor->delete();
            $count++;
            $this->events->dispatch(new FactorDisabled($user, $factor->type, null, ['factor_id' => $factor->id, 'via' => $by ?? 'app', 'by_administrator' => true]));
        }

        $this->live()->make(RecoveryCodes::class)->clear($user);
        $this->forgetCachedState($user);
        $this->revokeVerifications($user, $by, logout: true);

        return $count;
    }

    /**
     * End every verified session of this user on its next request, without
     * touching their factors (e.g. a role removed or an account suspended):
     * they pass MFA again, or with $logout sign in again. Their trusted
     * browsers end too, or one would verify them right away.
     */
    public function revokeVerifications(MultiFactorAuthenticatable $user, ?string $by = null, bool $byAdministrator = true, bool $logout = false): void
    {
        // Trusted browsers first: one used between the stamp and their removal
        // would verify again a second later, after the stamp.
        // Even while the feature is off: turned on again later, an old cookie must not verify them.
        $this->live()->make(TrustedBrowsers::class)->forget($user, TrustedBrowserRevocation::VerificationsRevoked);

        $this->live()->make(Revocations::class)->revoke($user->getAuthIdentifier(), $logout);

        // A new remember token, or a remember-me cookie would log them straight back in
        // (as Laravel's logout() does: only when there is one to replace).
        if ($logout && $user instanceof Model && $user->getRememberTokenName() !== '' && ! empty($user->getRememberToken())) {
            $user->forceFill([$user->getRememberTokenName() => Str::random(60)])->saveQuietly();
        }

        $this->events->dispatch(new VerificationsRevoked($user, null, null, array_filter([
            'by' => $by ?? 'app', 'by_administrator' => $byAdministrator ?: null, 'logout' => $logout ?: null,
        ])));
    }

    /** Whether a verification of this user made at $verifiedAt (unix seconds) was revoked since. */
    public function isRevoked(int|string $userId, int $verifiedAt): bool
    {
        return $this->live()->make(Revocations::class)->revokes($userId, $verifiedAt);
    }

    /**
     * The user's last revocation and logout (unix seconds, 0 = never): one
     * cache read.
     *
     * @return array{revoked: int, logged_out: int}
     */
    public function revocations(int|string $userId): array
    {
        $revocations = $this->live()->make(Revocations::class);
        $request = $this->liveRequest();

        // The gate asks for both on most requests: one round trip (MGET, or one
        // query on a database store) instead of two. Each is kept on the request.
        if ($request !== null && ! $request->attributes->has(self::MEMO_PREFIX.$userId)) {
            try {
                $values = $this->cacheStore()->many([$this->cacheKey($userId), $revocations->cacheKey($userId)]);
                if (is_string($types = $values[$this->cacheKey($userId)] ?? null)) {
                    $request->attributes->set(self::MEMO_PREFIX.$userId, $types);
                }
                $revocations->prime($userId, $values[$revocations->cacheKey($userId)] ?? null);
            } catch (Throwable) {
                // Each is read on its own below, with its own failure handling.
            }
        }

        return $revocations->stamps($userId);
    }

    /** When this session's guard logged its user in (unix seconds), 0 when unknown (before this was recorded). */
    public function loginAt(Session $session, string $guard, int|string $id): int
    {
        $at = $session->get(self::LOGIN_AT_PREFIX.'.'.$guard.'.'.$id);

        return is_numeric($at) ? (int) $at : 0;
    }

    /** When this session's verification for the guard and user was made (unix seconds), null if it isn't. */
    public function verifiedAt(Session $session, string $guard, int|string $id): ?int
    {
        $at = $session->get($this->sessionKey($guard, $id));

        return is_numeric($at) ? (int) $at : null;
    }

    public function lifetime(): VerificationLifetime
    {
        return $this->live()->make(VerificationLifetime::class);
    }

    /**
     * A one-time link that lets this user add their first factor without an
     * email code (enrollment_verification), for an administrator to hand
     * over on a channel they trust: for users without an email address, or
     * when email codes are off because the mailbox can't be trusted. Valid
     * for $minutes (enrollment_verification.link_ttl by default), only when
     * opened in the user's own signed-in session, and only until their
     * password changes. Emits EnrollmentLinkIssued (via "app"), which emails
     * the owner (notifications.events.enrollment_link_issued).
     */
    public function enrollmentLink(MultiFactorAuthenticatable $user, ?int $minutes = null): string
    {
        return $this->live()->make(EnrollmentLinks::class)->issue($user, $minutes);
    }

    /**
     * MFA state for the frontend (see Support\MfaContext), about the user who
     * logged in to this session. Cheap: one cached lookup for unverified users.
     */
    public function context(?Request $request = null): MfaContext
    {
        $request ??= $this->live()->make('request');
        $enabled = $this->enabled();
        $routes = $enabled && (bool) $this->config->get('mfa.routes.enabled') && Route::has('mfa.settings');

        $user = null;
        $identity = $enabled && $request->hasSession() ? $this->sessionIdentity($request) : null;
        $model = $identity?->user();

        if ($identity !== null && $model instanceof MultiFactorAuthenticatable) {
            $verified = $this->isVerifiedById($request->session(), $identity->guard, $identity->id);
            $user = [
                'hasMfa' => $this->hasConfirmedFactors($model),
                'verified' => $verified,
                'mustEnroll' => $verified
                    ? $this->mustEnrollAfterVerification($request->session(), $identity->guard, $identity->id)
                    : $this->mustEnroll($model),
            ];
        }

        // For the session user: whether they would be asked. For guests: whether anyone may be.
        $passwordConfirmation = (array) $this->config->get('mfa.routes.confirm_middleware') !== []
            || ($model instanceof MultiFactorAuthenticatable && $user !== null
                ? $this->requiresPasswordConfirmation($model)
                : (bool) $this->config->get('mfa.routes.password_confirmation'));

        return new MfaContext(
            enabled: $enabled,
            factors: array_map(fn (FactorType $type) => $type->value, $this->enabledTypes()),
            passwordConfirmation: $passwordConfirmation,
            user: $user,
            urls: [
                'settings' => $routes ? route('mfa.settings') : null,
                'challenge' => $routes ? route('mfa.challenge') : null,
            ],
            nudge: [
                'show' => $routes && $user !== null && $model instanceof MultiFactorAuthenticatable && $this->showsNudge($request, $model),
                ...$this->nudgeCopy(),
                'dismissUrl' => $routes ? route('mfa.nudge.dismiss') : null,
            ],
            reverifyReminder: $this->reverifyReminder($request, $routes, ($user['verified'] ?? false) ? $identity : null),
            verification: $this->verificationContext($request, $routes, ($user['verified'] ?? false) ? $identity : null),
        );
    }

    /**
     * The verified session's deadlines (mfa.lifetime) for the frontend's
     * timers, null when it has none. Read from the session: no query.
     *
     * @return array{profile: string, now: string, expiresAt: string|null, remindAt: string|null, graceUntil: string|null, idleSeconds: int|null, idleExpiresAt: string|null, renewUrl: string|null, keepAliveUrl: string|null, stateUrl: string|null}|null
     */
    private function verificationContext(Request $request, bool $routes, ?SessionIdentity $identity): ?array
    {
        $entry = $identity === null ? null : $this->lifetime()->entry($request->session(), $identity->guard, $identity->id);
        $described = $entry === null || $identity === null ? null : $this->lifetime()->describe($request->session(), $identity->guard, $identity->id, $entry);

        return $described === null ? null : [
            ...$described,
            'renewUrl' => $routes && $entry['until'] !== null ? route('mfa.challenge', ['renew' => 1]) : null,
            'keepAliveUrl' => $routes && $entry['idle'] !== null ? route('mfa.session.keep-alive') : null,
            'stateUrl' => $routes ? route('mfa.session') : null,
        ];
    }

    /**
     * The "check coming up" reminder, for a verified session off MFA's own
     * pages, not dismissed this session: the end of the lifetime window
     * (mfa.lifetime: reminder minutes) or of a trusted browser's trust
     * (trusted_browsers.reminder.hours). `show` says it is due now;
     * `showAt` lets an open page show it when it becomes due. Read from the
     * session first: no query on most pages.
     *
     * @return ReverifyReminder
     */
    private function reverifyReminder(Request $request, bool $routes, ?SessionIdentity $identity): array
    {
        $model = $identity?->user();
        $eligible = $routes && $identity !== null && $model instanceof MultiFactorAuthenticatable
            && ! ($request->route() !== null && $request->routeIs('mfa.*'))
            && ! $request->session()->get(self::REMINDER_DISMISSED);

        $reason = null;
        $expires = $showAt = null;
        $grace = 0;

        if ($eligible) {
            $entry = $this->lifetime()->entry($request->session(), $identity->guard, $identity->id);

            if ($entry !== null && $entry['until'] !== null && $entry['remind_at'] !== null) {
                // Through the grace period too: a page left open is still told.
                [$reason, $expires, $showAt, $grace] = ['lifetime', $entry['until'], $entry['remind_at'], $entry['grace']];
            } elseif (($trust = $this->live()->make(TrustedBrowsers::class)->reminderWindow($request, $model, $identity->guard)) !== null) {
                [$reason, $expires, $showAt] = ['trust', ...$trust];
            }
        }

        $now = now()->getTimestamp();
        $copy = $reason === 'lifetime' ? 'mfa.lifetime.reminder' : 'mfa.trusted_browsers.reminder';
        $text = fn (string $key): string => (string) __((string) $this->config->get("{$copy}.{$key}"));
        $iso = fn (?int $ts): ?string => $ts === null ? null : CarbonImmutable::createFromTimestamp($ts)->toIso8601String();
        $live = $expires !== null && $now < $expires + $grace;

        return [
            'show' => $live && $now >= $showAt,
            'reason' => $live ? $reason : null,
            'expiresAt' => $live ? $iso($expires) : null,
            'showAt' => $live ? $iso($showAt) : null,
            'title' => $text('title'),
            'body' => $text('body'),
            'button' => $text('button'),
            'dismissLabel' => $text('dismiss_label'),
            'verifyUrl' => $routes ? route('mfa.challenge', ['renew' => 1]) : null,
            'dismissUrl' => $routes ? route('mfa.reminder.dismiss') : null,
        ];
    }

    /**
     * The one rule for who the turn-on-two-factor nudge (config mfa.nudge) is
     * for: it is on, and the user has no factor and isn't enforced (enforced
     * users are sent to enroll anyway). The settings page's notice uses this
     * alone; the floating card also hides on MFA's own pages and once
     * dismissed (showsNudge()). A user with MFA costs nothing more.
     */
    public function nudgeEligible(MultiFactorAuthenticatable $user): bool
    {
        return $this->config->get('mfa.nudge.enabled')
            && ! $this->hasConfirmedFactors($user)
            && ! $this->mustEnroll($user);
    }

    /**
     * Whether the floating nudge shows for this session user on this page:
     * nudgeEligible(), not on MFA's own pages, and not dismissed. Checked in
     * that order, so the dismissal costs at most one cache read, and only
     * for users it is for.
     */
    private function showsNudge(Request $request, MultiFactorAuthenticatable $user): bool
    {
        return $this->nudgeEligible($user)
            && ! ($request->route() !== null && $request->routeIs('mfa.*'))
            && ! $this->live()->make(Nudge::class)->isDismissed($request->session(), $user);
    }

    /**
     * The nudge's copy from config('mfa.nudge'), through the translator, so
     * a lang/{locale}.json entry can translate it. The settings page shows
     * the title and body as a notice.
     *
     * @return array{title: string, body: string, button: string, dismissLabel: string}
     */
    public function nudgeCopy(): array
    {
        $text = fn (string $key): string => (string) __((string) $this->config->get("mfa.nudge.{$key}"));

        return [
            'title' => $text('title'),
            'body' => $text('body'),
            'button' => $text('button'),
            'dismissLabel' => $text('dismiss_label'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Extension points
    |--------------------------------------------------------------------------
    */

    /** Register a custom factor driver (e.g. passkeys, WhatsApp). */
    public function extend(string $type, Closure $callback): static
    {
        // Drop drivers built already, or a late extend() would be ignored.
        $this->app->make(FactorManager::class)->extend($type, $callback)->forgetDrivers();

        return $this;
    }

    /** Register a custom SMS transport: fn (Container $app, array $config): SmsSender. */
    public function extendSms(string $driver, Closure $callback): static
    {
        $this->app->make(SmsManager::class)->extend($driver, $callback);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Testing helpers
    |--------------------------------------------------------------------------
    */

    public function fakeSms(): FakeSmsSender
    {
        $fake = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $fake);

        return $fake;
    }

    /** Make every generated OTP equal to $code. */
    public function fakeCodes(string $code = '123456'): FixedCodeGenerator
    {
        $generator = new FixedCodeGenerator($code);
        $this->app->instance(CodeGenerator::class, $generator);
        $this->app->make(FactorManager::class)->forgetDrivers();

        return $generator;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return list<string> */
    public function guards(): array
    {
        // Equivalent mutant(s): guards is always a list in config.
        return array_values((array) $this->config->get('mfa.guards')); // @pest-mutate-ignore: UnwrapArrayValues,RemoveArrayCast
    }

    /** @return list<SessionIdentity> */
    public function sessionIdentities(Request $request): array
    {
        return SessionIdentity::resolveAll($request, $this->live()->make(AuthFactory::class), $this->guards());
    }

    /**
     * The identity the MFA screens act on: the first logged-in guard that
     * still needs MFA (unverified, and has factors or must enroll),
     * otherwise the first logged-in guard.
     */
    public function sessionIdentity(Request $request): ?SessionIdentity
    {
        $identities = $this->sessionIdentities($request);

        foreach ($identities as $identity) {
            if ($this->isVerifiedById($request->session(), $identity->guard, $identity->id)) {
                continue;
            }

            $user = $identity->user();

            if ($user instanceof MultiFactorAuthenticatable && ($this->hasConfirmedFactors($user) || $this->mustEnroll($user))) {
                return $identity;
            }
        }

        return $identities[0] ?? null;
    }

    public function sessionKey(string $guard, int|string $id): string
    {
        // Equivalent mutant(s): any consistent key works; logout clears the whole mfa.verified prefix.
        return self::SESSION_PREFIX.'.'.$guard.'.'.$id; // @pest-mutate-ignore: ConcatRemoveRight
    }

    /**
     * The guard this user is logged in with in this request (the first MFA
     * guard if none matches).
     */
    public function guardFor(Request $request, Authenticatable $user): string
    {
        $id = (string) $user->getAuthIdentifier();
        $pending = $this->sessionIdentity($request);

        // Equivalent mutant(s): session ids and auth identifiers share a type.
        if ($pending && (string) $pending->id === $id) { // @pest-mutate-ignore: RemoveStringCast
            return $pending->guard;
        }

        foreach ($this->sessionIdentities($request) as $identity) {
            // Equivalent mutant(s): session ids and auth identifiers share a type.
            if ((string) $identity->id === $id) { // @pest-mutate-ignore: RemoveStringCast
                return $identity->guard;
            }
        }

        return $this->guards()[0] ?? 'web';
    }

    /**
     * The container of the request being handled. Under Octane each request
     * runs in a clone of the app, set as the global instance, while
     * \$this->app is the one this singleton was built with at boot: anything
     * per request (the request, auth, a policy that needs either) comes from
     * here. Without Octane both are the same container.
     */
    private function live(): Container
    {
        return LiveContainer::getInstance();
    }

    /** The request being handled, if it has a session (see cachedConfirmedTypes()). */
    private function liveRequest(): ?Request
    {
        $container = $this->live();
        $request = $container->bound('request') ? $container->make('request') : null;

        return $request instanceof Request && $request->hasSession() ? $request : null;
    }

    private function cacheKey(int|string $userId): string
    {
        // "factor-types", not v0.x's "has-factors" (0/1): old entries are never read.
        return $this->config->get('mfa.cache.prefix').':factor-types:'.$userId;
    }

    private function cacheStore(): Cache
    {
        return $this->cache->store($this->config->get('mfa.cache.store'));
    }
}
