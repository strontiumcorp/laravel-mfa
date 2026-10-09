<?php

namespace StrontiumCorp\LaravelMfa\Support;

/**
 * What the challenge page needs to know about one factor beyond its public
 * fields: whether a usable code is already out, the whole seconds until a
 * resend is allowed (null = now), the whole seconds until that code expires
 * (null = none out), and how many digits its codes have.
 *
 * Merged into each factor of the challenge page's props as
 * {code_sent, retry_after, expires_in, code_length} (docs/json-mode.md).
 */
final class ChallengeState
{
    private function __construct(
        public readonly bool $codeSent,
        public readonly ?int $retryAfter,
        public readonly ?int $expiresIn,
        public readonly int $codeLength,
    ) {}

    /**
     * No code is out (never sent, expired, burned or used), or the factor
     * isn't delivered (TOTP). After a code was used by a successful
     * verification the next send may still wait $retryAfter seconds (null or
     * <= 0 = now): the cooldown curve spans logins.
     */
    public static function none(int $codeLength, ?int $retryAfter = null): self
    {
        return new self(false, $retryAfter !== null && $retryAfter > 0 ? $retryAfter : null, null, $codeLength);
    }

    /**
     * A usable code is out, valid for $expiresIn more seconds; a resend is
     * allowed in $retryAfter seconds (null or <= 0 = now).
     */
    public static function sent(?int $retryAfter, int $expiresIn, int $codeLength): self
    {
        return new self(true, $retryAfter !== null && $retryAfter > 0 ? $retryAfter : null, $expiresIn, $codeLength);
    }

    /** @return array{code_sent: bool, retry_after: int|null, expires_in: int|null, code_length: int} */
    public function toArray(): array
    {
        return [
            'code_sent' => $this->codeSent,
            'retry_after' => $this->retryAfter,
            'expires_in' => $this->expiresIn,
            'code_length' => $this->codeLength,
        ];
    }
}
