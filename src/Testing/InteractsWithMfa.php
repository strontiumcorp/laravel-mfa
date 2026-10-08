<?php

namespace StrontiumCorp\LaravelMfa\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Factors\TotpFactor;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;

/**
 * Helpers for host-application tests.
 *
 * Note: Laravel's actingAs() only sets the guard's user in memory and never
 * writes the session, so MFA (which checks the session identity) does not
 * apply to it — existing test suites keep passing unchanged. To test MFA
 * itself, log in "for real" with loginWithSession().
 *
 *   uses(InteractsWithMfa::class);
 *   $this->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
 *   $this->actingAsMfaVerified($user)->get('/dashboard')->assertOk();
 */
trait InteractsWithMfa
{
    /** Log in exactly like a login form does (identity stored in the session). */
    public function loginWithSession(Authenticatable $user, string $guard = 'web'): static
    {
        $this->app['auth']->guard($guard)->login($user);

        return $this;
    }

    /** Session login that has already passed MFA. */
    public function actingAsMfaVerified(Authenticatable $user, string $guard = 'web'): static
    {
        $this->loginWithSession($user, $guard);
        $this->app['session']->put(app(Mfa::class)->sessionKey($guard, $user->getAuthIdentifier()), now()->getTimestamp());

        return $this;
    }

    public function createMfaFactor(MultiFactorAuthenticatable $user, FactorType $type = FactorType::Totp, ?string $destination = null): MfaFactor
    {
        /** @var MfaFactor $factor */
        $factor = $user->mfaFactors()->create([
            'type' => $type,
            'label' => $type->label(),
            'secret' => $type === FactorType::Totp ? (new Google2FA)->generateSecretKey(32) : null,
            'destination' => $destination ?? match ($type) {
                FactorType::Email => $user->getMfaEmail(),
                FactorType::Sms => '+15555550100',
                FactorType::Totp => null,
            },
            'confirmed_at' => now(),
        ]);

        return $factor;
    }

    public function currentTotpCode(MfaFactor $factor): string
    {
        /** @var TotpFactor $driver */
        $driver = app(Mfa::class)->factor(FactorType::Totp);

        return $driver->currentCode((string) $factor->secret);
    }
}
