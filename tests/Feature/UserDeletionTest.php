<?php

use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaOtpCode;
use StrontiumCorp\LaravelMfa\Models\MfaRecoveryCode;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\User;

// MFA rows hang off a user_id foreign key: deleting a user removes their
// factors (with destinations), codes and recovery codes, even when the delete
// skips model events. Audit rows stay, unlinked, until the retention prune.

beforeEach(function () {
    Mfa::fakeSms();
    [$this->user, $factor] = $this->userWithFactor(FactorType::Sms);
    app(RecoveryCodes::class)->generate($this->user);
    $this->loginWithSession($this->user)->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
    $this->other = $this->userWithFactor()[0];

    expect(MfaOtpCode::count())->toBe(1)->and(MfaAuditLog::where('user_id', $this->user->id)->exists())->toBeTrue();
});

it('deletes factors and codes but keeps unlinked audit rows when a user is deleted', function () {
    $audit = MfaAuditLog::where('user_id', $this->user->id)->count();

    $this->user->delete();

    expect(MfaFactor::where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(MfaOtpCode::count())->toBe(0)
        ->and(MfaRecoveryCode::where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(MfaAuditLog::whereNull('user_id')->count())->toBe($audit)
        ->and(MfaFactor::where('user_id', $this->other->id)->exists())->toBeTrue();
});

it('cascades for query-builder deletes that skip model events too', function () {
    User::query()->whereKey($this->user->id)->delete();

    expect(MfaFactor::where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(MfaRecoveryCode::where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(MfaAuditLog::whereNull('user_id')->exists())->toBeTrue();
});
