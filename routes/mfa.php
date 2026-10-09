<?php

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Http\Controllers\ChallengeController;
use StrontiumCorp\LaravelMfa\Http\Controllers\EnrollmentVerificationController;
use StrontiumCorp\LaravelMfa\Http\Controllers\NudgeController;
use StrontiumCorp\LaravelMfa\Http\Controllers\SettingsController;
use StrontiumCorp\LaravelMfa\Http\Middleware\BindMfaContext;
use StrontiumCorp\LaravelMfa\Http\Middleware\EnsureMfaVerified;
use StrontiumCorp\LaravelMfa\Http\Middleware\RequireEnrollmentVerification;
use StrontiumCorp\LaravelMfa\Http\Middleware\RequirePasswordConfirmation;

// The app's own middleware first (e.g. password.confirm), then MFA's inline
// check, which passes once either one has confirmed the password.
$confirm = [...(array) config('mfa.routes.confirm_middleware'), RequirePasswordConfirmation::class];
$throttle = config('mfa.routes.throttle') ? ['throttle:'.config('mfa.routes.throttle')] : [];

Route::prefix(config('mfa.routes.prefix'))
    ->name('mfa.')
    ->middleware([...(array) config('mfa.routes.middleware'), EnsureMfaVerified::class, BindMfaContext::class, ...$throttle])
    ->whereNumber('factor')
    ->group(function () use ($confirm) {
        Route::get('challenge', [ChallengeController::class, 'show'])->name('challenge');
        Route::post('challenge/send', [ChallengeController::class, 'send'])->name('challenge.send');
        Route::post('challenge', [ChallengeController::class, 'verify'])->name('challenge.verify');
        Route::post('challenge/recover', [ChallengeController::class, 'recover'])->name('challenge.recover');

        Route::get('settings', [SettingsController::class, 'show'])->name('settings');
        Route::post('confirm-password', [SettingsController::class, 'confirmPassword'])->name('password.confirm');
        // A first factor also needs proof of ownership (enrollment_verification), after the password.
        Route::post('factors', [SettingsController::class, 'store'])->middleware([...$confirm, RequireEnrollmentVerification::class])->name('factors.store');
        Route::post('factors/{factor}/confirm', [SettingsController::class, 'confirm'])->middleware(RequireEnrollmentVerification::class)->name('factors.confirm');
        Route::post('factors/{factor}/resend', [SettingsController::class, 'resend'])->name('factors.resend');
        Route::delete('factors/{factor}', [SettingsController::class, 'destroy'])->middleware($confirm)->name('factors.destroy');
        Route::post('trusted-browsers/reminder/dismiss', [NudgeController::class, 'dismissTrustReminder'])->name('trusted-browsers.reminder.dismiss');
        Route::delete('trusted-browsers', [SettingsController::class, 'forgetTrustedBrowsers'])->name('trusted-browsers.destroy-all');
        Route::delete('trusted-browsers/{browser}', [SettingsController::class, 'forgetTrustedBrowsers'])->whereNumber('browser')->name('trusted-browsers.destroy');
        Route::post('recovery-codes', [SettingsController::class, 'regenerateRecoveryCodes'])->middleware($confirm)->name('recovery-codes.store');

        Route::post('enrollment-verification/send', [EnrollmentVerificationController::class, 'send'])->name('enrollment-verification.send');
        Route::post('enrollment-verification', [EnrollmentVerificationController::class, 'verify'])->name('enrollment-verification.verify');
        Route::get('enrollment-verification/{user}', [EnrollmentVerificationController::class, 'link'])->middleware(ValidateSignature::class)->name('enrollment-verification.link');

        // "Not today" on the turn-on-two-factor nudge (users without factors pass the gate).
        Route::post('nudge/dismiss', [NudgeController::class, 'dismiss'])->name('nudge.dismiss');
    });
