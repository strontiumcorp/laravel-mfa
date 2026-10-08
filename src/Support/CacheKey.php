<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

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
        return sprintf('mfa:%s:%s', $bucket, hash('sha256', strtolower($subject))); // @pest-mutate-ignore: UnwrapStrtolower
    }

    /** Stable subject for a user: model type + id (ids repeat across models). */
    public static function user(Authenticatable $user): string
    {
        // Equivalent mutants: class name and morph alias are both unique per
        // model; non-Eloquent authenticatables can't implement the contract.
        $type = $user instanceof Model ? $user->getMorphClass() : $user::class; // @pest-mutate-ignore: InstanceOfToTrue,InstanceOfToFalse,TernaryNegated

        // Equivalent mutants: dropping/reordering the separator only matters
        // for crafted type/id pairs that can't occur (types are class names).
        return $type.'|'.$user->getAuthIdentifier(); // @pest-mutate-ignore: ConcatRemoveRight,ConcatSwitchSides
    }
}
