<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Per-user limits for verification and password-confirmation attempts
 * (sends: see SendGuard).
 *
 * Every attempt is counted BEFORE it is checked: the cache increment is
 * atomic, so a burst of parallel requests cannot all pass a "too many?"
 * check before any of them records a hit.
 */
final class RateLimits
{
    private const MINUTE = 60;

    private const DAY = 86400;

    public function __construct(private readonly RateLimiter $limiter) {}

    /** Record a verification attempt; false if it exceeds a limit. */
    public function attemptVerify(Authenticatable $user): bool
    {
        $minute = $this->limiter->hit($this->key('verify', $user), self::MINUTE);
        $day = $this->limiter->hit($this->key('verify-day', $user), self::DAY);

        return $minute <= $this->limit('verify_per_minute') && $day <= $this->limit('verify_per_day');
    }

    public function clearVerify(Authenticatable $user): void
    {
        $this->limiter->clear($this->key('verify', $user));
        $this->limiter->clear($this->key('verify-day', $user));
    }

    /**
     * Seconds until verification may be retried: the longest window that is
     * actually exceeded (minute and/or day).
     */
    public function verifyAvailableIn(Authenticatable $user): int
    {
        return $this->availableIn($user, ['verify' => 'verify_per_minute', 'verify-day' => 'verify_per_day']);
    }

    /** Record a password attempt on the MFA password prompt; false if it exceeds a limit. */
    public function attemptPassword(Authenticatable $user): bool
    {
        $minute = $this->limiter->hit($this->key('password', $user), self::MINUTE);
        $day = $this->limiter->hit($this->key('password-day', $user), self::DAY);

        return $minute <= $this->limit('password_per_minute') && $day <= $this->limit('password_per_day');
    }

    public function clearPassword(Authenticatable $user): void
    {
        $this->limiter->clear($this->key('password', $user));
        $this->limiter->clear($this->key('password-day', $user));
    }

    /** Seconds until a password may be tried again (see verifyAvailableIn()). */
    public function passwordAvailableIn(Authenticatable $user): int
    {
        return $this->availableIn($user, ['password' => 'password_per_minute', 'password-day' => 'password_per_day']);
    }

    /** @param array<string, string> $limits cache key => config limit name */
    private function availableIn(Authenticatable $user, array $limits): int
    {
        // Equivalent mutant(s): only called after a refusal, so at least one window raises the wait above 1.
        $wait = 0; // @pest-mutate-ignore: IncrementInteger,DecrementInteger
        foreach ($limits as $key => $limit) {
            if ($this->limiter->attempts($this->key($key, $user)) > $this->limit($limit)) {
                $wait = max($wait, $this->limiter->availableIn($this->key($key, $user)));
            }
        }

        return $wait;
    }

    private function limit(string $name): int
    {
        // Equivalent mutant: limits are ints (or numeric strings from env).
        return (int) config("mfa.rate_limit.{$name}"); // @pest-mutate-ignore: RemoveIntegerCast
    }

    private function key(string $action, Authenticatable $user): string
    {
        return CacheKey::for($action, CacheKey::user($user));
    }
}
