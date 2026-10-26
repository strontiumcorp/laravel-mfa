<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use Throwable;

/**
 * A new set of recovery codes waiting to be saved: the codes of an
 * account's first method (verified, not yet enabled), or a new set for an
 * account that has some (the old ones keep working). Nothing is written
 * until the user has downloaded the file (GET recovery-codes/download) and
 * pressed Finish (POST recovery-codes/finish), so a user who walks away
 * keeps no method they can't recover from.
 *
 * Kept in this browser's session only, encrypted (the codes are plain
 * text until finished), per user, for PendingEnrollments::TTL_MINUTES. A
 * reload shows the same codes again.
 */
final class PendingRecoveryCodes
{
    private const KEY = 'mfa.recovery_codes_pending';

    /**
     * @param  list<string>  $codes
     * @param  int|string|null  $factorId  the method these codes come with (null: a new set)
     */
    public static function put(Session $session, MultiFactorAuthenticatable $user, array $codes, int|string|null $factorId): void
    {
        $session->put(self::key($user), [
            'codes' => Crypt::encryptString((string) json_encode($codes)),
            'factor_id' => $factorId === null ? null : (string) $factorId,
            'downloaded' => false,
            'at' => now()->getTimestamp(),
        ]);
    }

    /**
     * The waiting set, or null when there is none (or it expired).
     *
     * @return array{codes: list<string>, factor_id: string|null, downloaded: bool}|null
     */
    public static function get(Session $session, MultiFactorAuthenticatable $user): ?array
    {
        $entry = $session->get(self::key($user));

        if (! is_array($entry) || ! is_numeric($entry['at'] ?? null)
            || (int) $entry['at'] <= now()->subMinutes(PendingEnrollments::TTL_MINUTES)->getTimestamp()) {
            return null;
        }

        try {
            $codes = json_decode(Crypt::decryptString((string) ($entry['codes'] ?? '')), true);
        } catch (Throwable) {
            return null; // APP_KEY changed since: start again.
        }

        if (! is_array($codes) || $codes === []) {
            return null;
        }

        return [
            'codes' => array_values(array_map('strval', $codes)),
            'factor_id' => isset($entry['factor_id']) ? (string) $entry['factor_id'] : null,
            'downloaded' => (bool) ($entry['downloaded'] ?? false),
        ];
    }

    public static function markDownloaded(Session $session, MultiFactorAuthenticatable $user): void
    {
        if ($session->has(self::key($user))) {
            $session->put(self::key($user).'.downloaded', true);
        }
    }

    public static function forget(Session $session, MultiFactorAuthenticatable $user): void
    {
        $session->forget(self::key($user));
    }

    private static function key(MultiFactorAuthenticatable $user): string
    {
        return self::KEY.'.'.$user->getAuthIdentifier();
    }
}
