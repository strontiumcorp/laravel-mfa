<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

interface LifetimePolicy
{
    /**
     * The lifetime profile (a key of config('mfa.lifetime.profiles')) for
     * this user's verification. Asked when a challenge succeeds, not on
     * every request; an unknown name falls back to "default".
     */
    public function profile(MultiFactorAuthenticatable $user): string;
}
