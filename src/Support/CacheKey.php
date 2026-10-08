<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Cache/rate-limiter keys for MFA. One place, so every counter is namespaced
 * the same way and subjects (destinations, IPs, users) are never stored in
 * clear text in the cache.
 */
final class CacheKey
{
    public static function for(string $bucket, string $subject): string
    {
        // Equivalent mutant: subjects are normalised (lower-case) before they get here.
        // Keyed, so a cache dump can't be brute-forced back to phone numbers.
        return sprintf('mfa:%s:%s', $bucket, hash_hmac('sha256', strtolower($subject), (string) config('app.key'))); // @pest-mutate-ignore: UnwrapStrtolower
    }

    /** Stable subject for a user (one user model: see Mfa::userModel()). */
    public static function user(Authenticatable $user): string
    {
        return 'user|'.$user->getAuthIdentifier();
    }
}
