<?php

namespace StrontiumCorp\LaravelMfa\Support;

/**
 * Strips what must never reach MFA logs or the audit table from free text
 * (error messages): URLs (which can carry account ids or credentials), email
 * addresses and phone-number-like digit runs.
 */
final class Redact
{
    private const PATTERNS = [
        '#\b[a-z][a-z0-9+.-]*://\S+#i' => '[url]',
        '/[^\s@<>()"\']+@[^\s@<>()"\']+\.[a-z]{2,}/i' => '[email]',
        '/\+\d{6,15}\b|\b\d{8,15}\b/' => '[number]',
    ];

    public static function text(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * Redact every string in a (nested) context array.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function context(array $context): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => self::text($value),
            is_array($value) => self::context($value),
            default => $value,
        }, $context);
    }
}
