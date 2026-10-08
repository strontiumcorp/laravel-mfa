<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Binds unconfirmed factors to the browser session that created them.
 *
 * Without this, any other session on the same account (e.g. an attacker who
 * knows the password) could read a pending TOTP secret from the settings
 * page, or confirm the factor themselves.
 */
final class PendingEnrollments
{
    /** Unconfirmed factors older than this can no longer be confirmed. */
    public const TTL_MINUTES = 30;

    private const KEY = 'mfa.pending';

    public static function add(Session $session, int|string $factorId): void
    {
        // Equivalent mutants: ids() normalises on read and owns() only checks
        // membership, so de-duplication/re-indexing/casting here is cosmetic.
        $session->put(self::KEY, array_values(array_unique([...self::ids($session), (string) $factorId]))); // @pest-mutate-ignore: UnwrapArrayUnique,UnwrapArrayValues,RemoveStringCast
    }

    public static function owns(Session $session, int|string $factorId): bool
    {
        return in_array((string) $factorId, self::ids($session), true);
    }

    /** @return list<string> */
    private static function ids(Session $session): array
    {
        // Equivalent mutants: add() stores strings, and the key is always an array.
        return array_map('strval', (array) $session->get(self::KEY, [])); // @pest-mutate-ignore: UnwrapArrayMap,RemoveArrayCast
    }
}
