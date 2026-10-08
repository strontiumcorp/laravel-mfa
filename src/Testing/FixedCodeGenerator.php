<?php

namespace StrontiumCorp\LaravelMfa\Testing;

use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Support\RandomCodeGenerator;

/**
 * Deterministic OTPs for tests: Mfa::fakeCodes('123456').
 * Recovery codes stay random (they must be unique per user).
 */
final class FixedCodeGenerator implements CodeGenerator
{
    public function __construct(private readonly string $code = '123456') {}

    public function otp(int $length): string
    {
        return str_pad(substr($this->code, 0, $length), $length, '0');
    }

    public function recoveryCode(): string
    {
        return (new RandomCodeGenerator)->recoveryCode();
    }
}
