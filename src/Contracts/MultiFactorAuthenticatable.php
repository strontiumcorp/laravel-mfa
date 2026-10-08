<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaRecoveryCode;

/**
 * Implemented by the User model, usually via the HasMultiFactorAuthentication trait.
 */
interface MultiFactorAuthenticatable extends Authenticatable
{
    /** @return MorphMany<MfaFactor, Model> */
    public function mfaFactors(): MorphMany;

    /** @return MorphMany<MfaRecoveryCode, Model> */
    public function mfaRecoveryCodes(): MorphMany;

    /** Address used by the email factor by default. */
    public function getMfaEmail(): ?string;

    /** Label shown in authenticator apps, e.g. "jane@example.com". */
    public function getMfaLabel(): string;
}
