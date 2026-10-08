<?php

namespace StrontiumCorp\LaravelMfa\Support;

use StrontiumCorp\LaravelMfa\Enums\FailureReason;

final class VerificationResult
{
    /** @param array<string, mixed> $context */
    private function __construct(
        public readonly bool $successful,
        public readonly ?FailureReason $reason = null,
        public readonly array $context = [],
    ) {}

    /** @param array<string, mixed> $context */
    public static function success(array $context = []): self
    {
        return new self(true, null, $context);
    }

    /** @param array<string, mixed> $context */
    public static function failure(FailureReason $reason, array $context = []): self
    {
        return new self(false, $reason, $context);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }
}
