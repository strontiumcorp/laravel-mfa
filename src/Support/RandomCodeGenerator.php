<?php

namespace StrontiumCorp\LaravelMfa\Support;

use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;

final class RandomCodeGenerator implements CodeGenerator
{
    /** No 0/o, 1/l/i — easy to read back from paper. */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function otp(int $length): string
    {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            // Equivalent mutant(s): string concatenation converts the int.
            $code .= (string) random_int(0, 9); // @pest-mutate-ignore: RemoveStringCast
        }

        return $code;
    }

    public function recoveryCode(): string
    {
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= self::RECOVERY_ALPHABET[random_int(0, $max)];
        }

        return substr($code, 0, 5).'-'.substr($code, 5);
    }
}
