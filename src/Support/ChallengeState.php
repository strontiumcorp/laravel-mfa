<?php

namespace StrontiumCorp\LaravelMfa\Support;

/**
 * What the challenge page needs to know about one factor beyond its public
 * fields: whether a usable code is already out, the whole seconds until a
 * resend is allowed (null = now), and how many digits its codes have.
 *
 * Merged into each factor of the challenge page's props as
 * {code_sent, retry_after, code_length} (docs/json-mode.md).
 */
final class ChallengeState
{
    private function __construct(
        public readonly bool $codeSent,
        public readonly ?int $retryAfter,
        public readonly int $codeLength,
    ) {}

    /** No code is out (never sent, expired or burned), or the factor isn't delivered (TOTP). */
    public static function none(int $codeLength): self
    {
        return new self(false, null, $codeLength);
    }

    /** A usable code is out; a resend is allowed in $retryAfter seconds (null or <= 0 = now). */
    public static function sent(?int $retryAfter, int $codeLength): self
    {
        return new self(true, $retryAfter !== null && $retryAfter > 0 ? $retryAfter : null, $codeLength);
    }

    /** @return array{code_sent: bool, retry_after: int|null, code_length: int} */
    public function toArray(): array
    {
        return [
            'code_sent' => $this->codeSent,
            'retry_after' => $this->retryAfter,
            'code_length' => $this->codeLength,
        ];
    }
}
