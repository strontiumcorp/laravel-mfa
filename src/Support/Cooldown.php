<?php

namespace StrontiumCorp\LaravelMfa\Support;

/**
 * Resend cooldown curve: base × multiplier^(n-1), capped at max, where n is
 * the number of codes already sent in the current streak.
 *
 * With the defaults (120s, ×2, 900s max): 2 → 4 → 8 → 15 → 15 … minutes.
 */
final class Cooldown
{
    /**
     * Seconds to wait after the n-th send of a streak.
     *
     * @param  int|array{base?: int, multiplier?: int|float, max?: int}  $config  int = flat (older configs)
     */
    public static function after(int $sends, int|array $config): int
    {
        if ($sends < 1) {
            return 0;
        }

        if (is_int($config)) {
            return max(0, $config);
        }

        // Equivalent mutant(s): numeric strings work arithmetically; the return is cast.
        $base = max(0, (int) ($config['base'] ?? 120)); // @pest-mutate-ignore: RemoveIntegerCast
        // Equivalent mutant(s): numeric strings work arithmetically; the return is cast.
        $multiplier = max(1, (float) ($config['multiplier'] ?? 2)); // @pest-mutate-ignore: RemoveDoubleCast
        // Equivalent mutant(s): numeric strings work arithmetically; the return is cast.
        $max = max($base, (int) ($config['max'] ?? 900)); // @pest-mutate-ignore: RemoveIntegerCast

        // Equivalent mutant(s): the int return type coerces the integral result.
        return (int) min($max, $base * $multiplier ** ($sends - 1)); // @pest-mutate-ignore: RemoveIntegerCast
    }

    /**
     * after(), but never longer than the code's lifetime: once the code is
     * out of date, a new one may be sent at once.
     *
     * @param  int|array{base?: int, multiplier?: int|float, max?: int}  $config
     */
    public static function capped(int $sends, int|array $config, int $ttl): int
    {
        return min(self::after($sends, $config), $ttl);
    }
}
