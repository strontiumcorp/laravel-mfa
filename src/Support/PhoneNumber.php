<?php

namespace StrontiumCorp\LaravelMfa\Support;

/**
 * Dependency-free E.164 handling. We deliberately avoid guessing national
 * formats: the UI must submit numbers with a leading "+" and country code.
 */
final class PhoneNumber
{
    public static function normalize(string $input): ?string
    {
        // Equivalent mutant(s): the regex already strips whitespace; preg_replace only returns null on a regex error.
        $number = preg_replace('/[\s\-\.\(\)]/', '', trim($input)) ?? ''; // @pest-mutate-ignore: UnwrapTrim,EmptyStringToNotEmpty

        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $number) === 1 ? $number : null;
    }

    /**
     * @param  list<string|int>  $allowedCallingCodes
     * @param  list<string|int>  $blockedPrefixes
     */
    public static function canReceive(string $e164, array $allowedCallingCodes, array $blockedPrefixes = []): bool
    {
        $digits = ltrim($e164, '+');

        foreach ($blockedPrefixes as $prefix) {
            // Equivalent mutant(s): str_starts_with() coerces int prefixes from config.
            if (str_starts_with($digits, (string) $prefix)) { // @pest-mutate-ignore: RemoveStringCast
                return false;
            }
        }

        return self::isAllowed($e164, $allowedCallingCodes);
    }

    /** @param list<string|int> $allowedCallingCodes */
    public static function isAllowed(string $e164, array $allowedCallingCodes): bool
    {
        if ($allowedCallingCodes === []) {
            return true;
        }

        $digits = ltrim($e164, '+');

        foreach ($allowedCallingCodes as $code) {
            // Equivalent mutant(s): str_starts_with() coerces int calling codes from config.
            if (str_starts_with($digits, (string) $code)) { // @pest-mutate-ignore: RemoveStringCast
                return true;
            }
        }

        return false;
    }
}
