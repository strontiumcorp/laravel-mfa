<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaRecoveryCode;

/**
 * Implemented by the User model, usually via the HasMultiFactorAuthentication trait.
 */
interface MultiFactorAuthenticatable extends Authenticatable
{
    /** @return HasMany<MfaFactor, Model> */
    public function mfaFactors(): HasMany;

    /** @return HasMany<MfaRecoveryCode, Model> */
    public function mfaRecoveryCodes(): HasMany;

    /** Address used by the email factor by default. */
    public function getMfaEmail(): ?string;

    /** Label shown in authenticator apps, e.g. "jane@example.com". */
    public function getMfaLabel(): string;
}
