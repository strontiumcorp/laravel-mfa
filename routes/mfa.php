<?php

use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Http\Controllers\ChallengeController;
use StrontiumCorp\LaravelMfa\Http\Controllers\SettingsController;
use StrontiumCorp\LaravelMfa\Http\Middleware\BindMfaContext;
use StrontiumCorp\LaravelMfa\Http\Middleware\EnsureMfaVerified;

$confirm = (array) config('mfa.routes.confirm_middleware');
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
        Route::post('factors', [SettingsController::class, 'store'])->middleware($confirm)->name('factors.store');
        Route::post('factors/{factor}/confirm', [SettingsController::class, 'confirm'])->name('factors.confirm');
        Route::post('factors/{factor}/resend', [SettingsController::class, 'resend'])->name('factors.resend');
        Route::delete('factors/{factor}', [SettingsController::class, 'destroy'])->middleware($confirm)->name('factors.destroy');
        Route::post('recovery-codes', [SettingsController::class, 'regenerateRecoveryCodes'])->middleware($confirm)->name('recovery-codes.store');
    });
