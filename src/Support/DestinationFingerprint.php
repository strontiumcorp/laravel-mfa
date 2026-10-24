<?php

namespace StrontiumCorp\LaravelMfa\Support;

use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;

/**
 * What the audit trail may say about where an email or SMS factor sends its
 * codes, without the address itself: a keyed fingerprint (the same address
 * gives the same value on any account, so one attacker address can be traced
 * across accounts) and, for email, whether it is the account's own address
 * (then whoever confirmed the factor read that inbox).
 *
 * The fingerprint is an HMAC under the current APP_KEY (CodeHasher), so it
 * can't be reversed by guessing addresses, and values from before an APP_KEY
 * rotation don't match values after it.
 */
final class DestinationFingerprint
{
    private const SCOPE = 'destination';

    public function __construct(private readonly CodeHasher $hasher) {}

    /** @return array{destination_fingerprint?: string, destination_is_account_email?: bool} */
    public function context(MfaFactor $factor, ?MultiFactorAuthenticatable $user): array
    {
        $destination = $factor->destination;

        if ($destination === null || $destination === '') {
            return [];
        }

        $normalized = self::normalize($destination);
        // The prefix keeps an all-digit value from looking like a phone number to Redact.
        $context = ['destination_fingerprint' => 'fp_'.substr($this->hasher->hash($normalized, self::SCOPE), 0, 16)];

        if ($factor->type === FactorType::Email) {
            $own = $user?->getMfaEmail();
            $context['destination_is_account_email'] = $own !== null && self::normalize($own) === $normalized;
        }

        return $context;
    }

    private static function normalize(string $destination): string
    {
        return strtolower(trim($destination));
    }
}
