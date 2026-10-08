<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Per-user limits for verification attempts (sends: see SendGuard).
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
        $limits = ['verify' => 'verify_per_minute', 'verify-day' => 'verify_per_day'];

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
