<?php

namespace StrontiumCorp\LaravelMfa\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaRecoveryCode;
use UnitEnum;

/**
 * Add to your User model alongside `implements MultiFactorAuthenticatable`.
 * Override getMfaEmail() / getMfaLabel() if your columns differ.
 */
trait HasMultiFactorAuthentication
{
    /** @return MorphMany<MfaFactor, $this> */
    public function mfaFactors(): MorphMany
    {
        return $this->morphMany(MfaFactor::class, 'authenticatable');
    }

    /** @return MorphMany<MfaRecoveryCode, $this> */
    public function mfaRecoveryCodes(): MorphMany
    {
        return $this->morphMany(MfaRecoveryCode::class, 'authenticatable');
    }

    /** @return MorphMany<MfaAuditLog, $this> */
    public function mfaAuditLogs(): MorphMany
    {
        return $this->morphMany(MfaAuditLog::class, 'authenticatable');
    }

    public function getMfaEmail(): ?string
    {
        return $this->getAttribute('email');
    }

    public function getMfaLabel(): string
    {
        return (string) ($this->getAttribute('email') ?? $this->getAuthIdentifier());
    }

    /**
     * Roles matched against config('mfa.enforce') when it is a list of
     * roles. Reads the "role" attribute (string, backed enum or a list).
     * Override for other role systems, e.g. spatie/laravel-permission:
     * `return $this->getRoleNames()->all();`
     *
     * @return list<string>
     */
    public function getMfaRoles(): array
    {
        return collect($this->getAttribute('role'))
            ->filter(fn ($role) => $role !== null)
            ->map(fn ($role) => match (true) {
                $role instanceof BackedEnum => (string) $role->value,
                $role instanceof UnitEnum => $role->name,
                default => (string) $role,
            })
            ->values()
            ->all();
    }

    public function hasMfaEnabled(): bool
    {
        return app(Mfa::class)->hasConfirmedFactors($this);
    }
}
