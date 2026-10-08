<?php

namespace StrontiumCorp\LaravelMfa\Support;

use StrontiumCorp\LaravelMfa\Enums\FactorType;

final class Mask
{
    public static function destination(FactorType $type, ?string $destination): ?string
    {
        return match (true) {
            $destination === null || $destination === '' => null,
            $type === FactorType::Email => self::email($destination),
            $type === FactorType::Sms => self::phone($destination),
            default => null,
        };
    }

    public static function email(string $email): string
    {
        [$local, $domain] = str_contains($email, '@') ? explode('@', $email, 2) : [$email, ''];

        return mb_substr($local, 0, 1).str_repeat('*', max(mb_strlen($local) - 1, 3)).'@'.$domain;
    }

    public static function phone(string $phone): string
    {
        $visible = 4;
        $length = strlen($phone);

        if ($length <= $visible + 1) {
            return str_repeat('*', $length);
        }

        return '+'.str_repeat('*', $length - $visible - 1).substr($phone, -$visible);
    }
}
