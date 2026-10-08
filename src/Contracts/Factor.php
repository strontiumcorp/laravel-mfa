<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;

interface Factor
{
    public function type(): FactorType;

    /**
     * Create an unconfirmed factor. Returns data the UI needs to finish
     * enrollment (QR code for TOTP, masked destination for OTP factors).
     *
     * @param  array<string, mixed>  $input
     * @return array{factor: MfaFactor, setup: array<string, mixed>}
     */
    public function enroll(MultiFactorAuthenticatable $user, array $input): array;

    /**
     * Send a challenge for this factor (no-op for TOTP).
     */
    public function challenge(MfaFactor $factor): VerificationResult;

    /**
     * Verify a code against this factor. Never throws for bad input.
     */
    public function verify(MfaFactor $factor, string $code): VerificationResult;
}
