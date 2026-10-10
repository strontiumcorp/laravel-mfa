<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Session\Session;
use StrontiumCorp\LaravelMfa\Enums\FactorType;

/**
 * The required types an enforced user has already passed in the challenge
 * they are in (Mfa::challengeRequirement(), "all"), per guard and user, in
 * the session. Each counts for a few minutes from when it was passed, so a
 * half-finished challenge can't be completed much later; cleared by the
 * verification it completes, a revocation, and logging in or out.
 */
final class ChallengeSteps
{
    public const SESSION_KEY = 'mfa.challenge_steps';

    /** How long a passed step counts while the next ones are entered. */
    public const TTL_SECONDS = 600;

    /** @return list<string> the passed type values still counting, oldest first */
    public static function passed(Session $session, string $guard, int|string $id): array
    {
        return array_keys(self::current($session, $guard, $id));
    }

    /** @return list<string> the passed type values, with this one */
    public static function pass(Session $session, string $guard, int|string $id, FactorType $type): array
    {
        $steps = self::current($session, $guard, $id);
        // Passing a type again doesn't move it up, nor keep the others alive.
        $steps[$type->value] ??= now()->getTimestamp();
        $session->put(self::key($guard, $id), $steps);

        return array_keys($steps);
    }

    /** @return array<string, int> type value => when it was passed, those still counting */
    private static function current(Session $session, string $guard, int|string $id): array
    {
        $since = now()->getTimestamp() - self::TTL_SECONDS;

        return array_filter(
            array_map('intval', array_filter((array) $session->get(self::key($guard, $id), []), 'is_numeric')),
            fn (int $at) => $at >= $since,
        );
    }

    private static function key(string $guard, int|string $id): string
    {
        return self::SESSION_KEY.'.'.$guard.'.'.$id;
    }
}
