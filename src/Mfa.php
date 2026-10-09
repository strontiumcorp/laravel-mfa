<?php

namespace StrontiumCorp\LaravelMfa;

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
use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\Factor;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Contracts\PasswordConfirmationPolicy;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ImpersonationGranted;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Exceptions\ImpersonationNotAllowed;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Policies\EnforceForRoles;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;
use StrontiumCorp\LaravelMfa\Support\MfaContext;
use StrontiumCorp\LaravelMfa\Support\Nudge;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use StrontiumCorp\LaravelMfa\Support\SessionIdentity;
use StrontiumCorp\LaravelMfa\Testing\FakeSmsSender;
use StrontiumCorp\LaravelMfa\Testing\FixedCodeGenerator;

/**
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

    /** When the password was last confirmed: Laravel's own key, shared with password.confirm. */
    public const PASSWORD_CONFIRMED_AT = 'auth.password_confirmed_at';

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
        $key = $this->cacheKey($user->getAuthIdentifier());
        $cached = $this->cacheStore()->get($key);

        if (is_string($cached)) {
            return $this->anyEnabled($cached);
        }

        $types = $this->queryConfirmedTypes($user->getAuthIdentifier());

        // add(), not put(): if a factor changed while we were querying, its
        // model event has already written the fresh answer — never let this
        // possibly-stale read overwrite it.
        // Equivalent mutant(s): ttl is an int.
        $this->cacheStore()->add($key, $types, (int) $this->config->get('mfa.cache.ttl')); // @pest-mutate-ignore: RemoveIntegerCast

        return $this->anyEnabled($types);
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
        $this->cacheStore()->put(
            $this->cacheKey($userId),
            $this->queryConfirmedTypes($userId),
            (int) $this->config->get('mfa.cache.ttl'),
        );
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
     * they have no confirmed factor of a required type (any enabled type
     * when enforcement.required_types names none that is enabled).
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

        return array_intersect($this->typeValues($required), $this->typeValues($this->confirmedTypes($user))) === [];
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
     * The types this user may verify with at the challenge: for an enforced
     * user who has a required factor, only the required types; otherwise
     * every enabled type (an enforced user without one verifies with what
     * they have, then must enroll).
     *
     * @return list<FactorType>
     */
    public function challengeTypes(MultiFactorAuthenticatable $user): array
    {
        $required = $this->requiredTypes();

        if ($required === [] || ! $this->enforcesAnyone() || ! $this->isEnforced($user)) {
            return $this->enabledTypes();
        }

        $held = array_intersect($this->typeValues($required), $this->typeValues($this->confirmedTypes($user)));

        return $held === [] ? $this->enabledTypes() : array_map(fn (string $value) => FactorType::from($value), array_values($held));
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
        $request->session()->regenerate();

        $this->events->dispatch(new VerificationSucceeded($user, $via, null, $context));

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
     * Otherwise the impersonator only needs to be MFA-satisfied. Any password
     * confirmation in the session is dropped: it was the impersonator's.
     */
    public function grantForImpersonation(Authenticatable $impersonator, Authenticatable $target, ?Request $request = null): void
    {
        // Resolve from the live container, not the one captured when this
        // singleton was built: under Octane each request runs in a clone.
        $request ??= $this->live()->make('request');

        $allowed = $target instanceof MultiFactorAuthenticatable && $this->hasConfirmedFactors($target)
            ? $this->isVerifiedFor($request, $impersonator)
            : ! $impersonator instanceof MultiFactorAuthenticatable || $this->isSatisfied($request, $impersonator);

        if (! $allowed) {
            throw ImpersonationNotAllowed::impersonatorNotVerified();
        }

        $request->session()->put(
            $this->sessionKey($this->guardFor($request, $target), $target->getAuthIdentifier()),
            now()->getTimestamp(),
        );
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
        );
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

    private function guardFor(Request $request, Authenticatable $user): string
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
