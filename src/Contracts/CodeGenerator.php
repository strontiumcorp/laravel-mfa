<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

interface CodeGenerator
{
    /** Numeric one-time code of the given length. */
    public function otp(int $length): string;

    /** Human-friendly recovery code, e.g. "a3f9k-2m8qx". */
    public function recoveryCode(): string;
}
