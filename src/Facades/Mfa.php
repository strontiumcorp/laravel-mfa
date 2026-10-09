<?php

namespace StrontiumCorp\LaravelMfa\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static list<\StrontiumCorp\LaravelMfa\Enums\FactorType> enabledTypes()
 * @method static bool isTypeEnabled(\StrontiumCorp\LaravelMfa\Enums\FactorType $type)
 * @method static bool isTypeRecommended(\StrontiumCorp\LaravelMfa\Enums\FactorType $type)
 * @method static \StrontiumCorp\LaravelMfa\Contracts\Factor factor(\StrontiumCorp\LaravelMfa\Enums\FactorType|string $type)
 * @method static bool hasConfirmedFactors(\StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable $user)
 * @method static bool mustEnroll(\StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable $user)
 * @method static bool isEnforced(\StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable $user)
 * @method static bool enforcesAnyone()
 * @method static list<\StrontiumCorp\LaravelMfa\Enums\FactorType> requiredTypes()
 * @method static list<\StrontiumCorp\LaravelMfa\Enums\FactorType> challengeTypes(\StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable $user)
 * @method static list<\StrontiumCorp\LaravelMfa\Enums\FactorType> confirmedTypes(\StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable $user)
 * @method static \StrontiumCorp\LaravelMfa\Mfa enforceUsing(?\Closure $callback)
 * @method static bool enforcesInCode()
 * @method static \StrontiumCorp\LaravelMfa\Support\MfaContext context(?\Illuminate\Http\Request $request = null)
 * @method static bool isVerified(\Illuminate\Contracts\Session\Session $session, \Illuminate\Contracts\Auth\Authenticatable $user, ?string $guard = null)
 * @method static bool isVerifiedFor(\Illuminate\Http\Request $request, \Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static void markVerified(\Illuminate\Http\Request $request, \Illuminate\Contracts\Auth\Authenticatable $user, ?\StrontiumCorp\LaravelMfa\Enums\FactorType $via = null, array<string, mixed> $context = [])
 * @method static void grantForImpersonation(\Illuminate\Contracts\Auth\Authenticatable $impersonator, \Illuminate\Contracts\Auth\Authenticatable $target, ?\Illuminate\Http\Request $request = null)
 * @method static \StrontiumCorp\LaravelMfa\Mfa extend(string $type, \Closure $callback)
 * @method static \StrontiumCorp\LaravelMfa\Mfa extendSms(string $driver, \Closure $callback)
 * @method static \StrontiumCorp\LaravelMfa\Testing\FakeSmsSender fakeSms()
 * @method static \StrontiumCorp\LaravelMfa\Testing\FixedCodeGenerator fakeCodes(string $code = '123456')
 * @method static void ignoreMigrations()
 *
 * @see \StrontiumCorp\LaravelMfa\Mfa
 */
class Mfa extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \StrontiumCorp\LaravelMfa\Mfa::class;
    }
}
