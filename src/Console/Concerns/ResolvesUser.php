<?php

namespace StrontiumCorp\LaravelMfa\Console\Concerns;

use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

trait ResolvesUser
{
    private function resolveUser(): ?MultiFactorAuthenticatable
    {
        $guard = $this->option('guard') ?: config('mfa.guards.0');
        $model = config('auth.providers.'.config("auth.guards.{$guard}.provider").'.model');
        $needle = (string) $this->argument('user');

        $instance = new $model;
        $isEmail = str_contains($needle, '@');

        // Strict ids: MySQL would coerce "12abc" to 12 and match the wrong user.
        if (! $isEmail && $instance->getKeyType() === 'int' && ! ctype_digit($needle)) {
            $this->components->error("[{$needle}] is not a valid user id or email.");

            return null;
        }

        $user = $model::query()
            ->when($isEmail, fn ($q) => $q->where('email', $needle), fn ($q) => $q->whereKey($needle))
            ->first();

        if (! $user instanceof MultiFactorAuthenticatable) {
            $this->components->error("No MFA-capable user found for [{$needle}] on guard [{$guard}].");

            return null;
        }

        return $user;
    }
}
