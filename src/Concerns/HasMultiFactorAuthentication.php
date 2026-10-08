<?php

namespace StrontiumCorp\LaravelMfa\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaRecoveryCode;

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

    public function hasMfaEnabled(): bool
    {
        return app(Mfa::class)->hasConfirmedFactors($this);
    }
}
