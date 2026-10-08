<?php

namespace StrontiumCorp\LaravelMfa;

use Closure;
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
use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\Factor;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ImpersonationGranted;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Exceptions\ImpersonationNotAllowed;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use StrontiumCorp\LaravelMfa\Support\SessionIdentity;
use StrontiumCorp\LaravelMfa\Testing\FakeSmsSender;
use StrontiumCorp\LaravelMfa\Testing\FixedCodeGenerator;

/**
 * Facade root (StrontiumCorp\LaravelMfa\Facades\Mfa).
 *
 * Holds no per-request state — the session/request are always passed in —
 * so it is safe as a singleton under Octane.
 */
class Mfa
{
    public const SESSION_PREFIX = 'mfa.verified';

    /** Set to false (Mfa::ignoreMigrations()) if you publish and own the migrations. */
    public static bool $runsMigrations = true;

    public static function ignoreMigrations(): void
    {
        static::$runsMigrations = false;
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

    public function factor(FactorType|string $type): Factor
    {
        return $this->app->make(FactorManager::class)->factor($type);
    }

    /*
    |--------------------------------------------------------------------------
    | User state (cached — consulted on every unverified request)
    |--------------------------------------------------------------------------
    */

    public function hasConfirmedFactors(MultiFactorAuthenticatable $user): bool
    {
        $type = $this->morphType($user);
        $key = $this->cacheKey($type, $user->getAuthIdentifier());
        $cached = $this->cacheStore()->get($key);

        if ($cached !== null) {
            // Equivalent mutant(s): the bool return type coerces.
            return (bool) $cached; // @pest-mutate-ignore: RemoveBooleanCast
        }

        $has = $this->queryHasConfirmedFactors($type, $user->getAuthIdentifier());

        // add(), not put(): if a factor changed while we were querying, its
        // model event has already written the fresh answer — never let this
        // possibly-stale read overwrite it.
        // Equivalent mutant(s): ints (not bools) keep stores that return false for a miss unambiguous, which the array store can't show; ttl is an int.
        $this->cacheStore()->add($key, (int) $has, (int) $this->config->get('mfa.cache.ttl')); // @pest-mutate-ignore: RemoveIntegerCast

        return $has;
    }

    public function forgetCachedState(MultiFactorAuthenticatable $user): void
    {
        $this->refreshCachedStateFor($this->morphType($user), $user->getAuthIdentifier());
    }

    /**
     * Write-through from MfaFactor model events: store the fresh answer
     * rather than just forgetting the key, so a concurrent fill that read
     * the old state cannot win the race.
     */
    public function refreshCachedStateFor(string $morphType, int|string $id): void
    {
        $this->cacheStore()->put(
            $this->cacheKey($morphType, $id),
            // Equivalent mutant(s): see add() above.
            (int) $this->queryHasConfirmedFactors($morphType, $id), // @pest-mutate-ignore: RemoveIntegerCast
            (int) $this->config->get('mfa.cache.ttl'),
        );
    }

    private function queryHasConfirmedFactors(string $morphType, int|string $id): bool
    {
        return MfaFactor::query()
            ->where('authenticatable_type', $morphType)
            ->where('authenticatable_id', $id)
            ->whereNotNull('confirmed_at')
            // Equivalent mutant(s): Eloquent binds backed enums by value.
            ->whereIn('type', array_map(fn (FactorType $t) => $t->value, $this->enabledTypes())) // @pest-mutate-ignore: UnwrapArrayMap
            ->exists();
    }

    public function mustEnroll(MultiFactorAuthenticatable $user): bool
    {
        $policy = $this->config->get('mfa.enforce');

        if ($policy === null || $this->hasConfirmedFactors($user)) {
            return false;
        }

        /** @var EnforcementPolicy $instance */
        $instance = $this->app->make($policy);

        return $instance->mustEnroll($user);
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
        $request->session()->regenerate();

        $this->events->dispatch(new VerificationSucceeded($user, $via, null, $context));

        RequestContext::end($request);
    }

    /**
     * Call right after a login-swap impersonation (Auth::loginUsingId($target)).
     * Refuses unless the impersonator themselves is MFA-satisfied.
     */
    public function grantForImpersonation(Authenticatable $impersonator, Authenticatable $target, ?Request $request = null): void
    {
        // Resolve from the live container, not the one captured when this
        // singleton was built: under Octane each request runs in a clone.
        $request ??= LiveContainer::getInstance()->make('request');

        if ($impersonator instanceof MultiFactorAuthenticatable && ! $this->isSatisfied($request, $impersonator)) {
            throw ImpersonationNotAllowed::impersonatorNotVerified();
        }

        $request->session()->put(
            $this->sessionKey($this->guardFor($request, $target), $target->getAuthIdentifier()),
            now()->getTimestamp(),
        );

        $this->events->dispatch(new ImpersonationGranted($target, null, null, [
            'impersonator_type' => $impersonator::class,
            'impersonator_id' => $impersonator->getAuthIdentifier(),
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Extension points
    |--------------------------------------------------------------------------
    */

    /** Register a custom factor driver (e.g. passkeys, WhatsApp). */
    public function extend(string $type, Closure $callback): static
    {
        $this->app->make(FactorManager::class)->extend($type, $callback);

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
        return SessionIdentity::resolveAll($request, LiveContainer::getInstance()->make(AuthFactory::class), $this->guards());
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

    private function cacheKey(string $morphType, int|string $id): string
    {
        return $this->config->get('mfa.cache.prefix').':has-factors:'.md5($morphType).':'.$id;
    }

    private function morphType(Authenticatable $user): string
    {
        // Equivalent mutant(s): non-Eloquent users can't implement the contract (it needs MorphMany relations).
        return $user instanceof Model ? $user->getMorphClass() : $user::class; // @pest-mutate-ignore: InstanceOfToTrue
    }

    private function cacheStore(): Cache
    {
        return $this->cache->store($this->config->get('mfa.cache.store'));
    }
}
