<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\PasswordConfirmationFailed;
use StrontiumCorp\LaravelMfa\Events\PasswordConfirmationRequired;
use StrontiumCorp\LaravelMfa\Events\PasswordConfirmed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\ExemptSocialLogins;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\HandleImpersonation;

// routes.password_confirmation: the settings endpoints that change factors
// answer "password confirmation required"; the page asks for the password
// (POST mfa.password.confirm) and retries. No app confirm page involved.

beforeEach(fn () => config(['mfa.routes.password_confirmation' => true]));

function passwordRequiredJson(): array
{
    return [
        'message' => 'Please confirm your password to continue.',
        'error' => 'password_confirmation_required',
        'confirm_url' => route('mfa.password.confirm'),
    ];
}

it('asks for the password before adding a factor, then allows it', function () {
    $this->freezeSecond();
    Event::fake([PasswordConfirmationRequired::class]);
    $this->loginWithSession($user = $this->makeUser());

    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423)->assertExactJson(passwordRequiredJson());
    expect(MfaFactor::count())->toBe(0);
    Event::assertDispatched(PasswordConfirmationRequired::class, fn ($e) => $e->user->is($user) && $e->context === ['path' => '/mfa/factors']);

    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk()->assertExactJson(['status' => 'password-confirmed']);
    expect(session(StrontiumCorp\LaravelMfa\Mfa::PASSWORD_CONFIRMED_AT))->toBe(now()->getTimestamp());

    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk()->assertJsonPath('status', 'enrollment-started');
});

it('asks before removing a factor and regenerating recovery codes, not before confirming or resending', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Totp);
    $this->actingAsMfaVerified($user);

    $this->deleteJson(route('mfa.factors.destroy', $factor))->assertStatus(423);
    $this->postJson(route('mfa.recovery-codes.store'))->assertStatus(423);
    expect($factor->fresh())->not->toBeNull();

    // Confirming a started enrollment proves possession; it never asks.
    $this->postJson(route('mfa.factors.confirm', 999), ['code' => '123456'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson(route('mfa.factors.resend', 999))->assertStatus(422)->assertJsonValidationErrors('code');

    $this->withConfirmedPassword();
    $this->postJson(route('mfa.recovery-codes.store'))->assertOk();
    $this->deleteJson(route('mfa.factors.destroy', $factor))->assertOk();
});

it('rejects a wrong password, and records both outcomes', function () {
    Event::fake([PasswordConfirmed::class, PasswordConfirmationFailed::class]);
    $this->loginWithSession($user = $this->makeUser());

    $this->postJson(route('mfa.password.confirm'), ['password' => 'wrong'])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'The provided password is incorrect.', 'errors' => ['password' => ['The provided password is incorrect.']]]);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
    Event::assertDispatched(PasswordConfirmationFailed::class, fn ($e) => $e->user->is($user) && $e->reason === FailureReason::InvalidPassword);

    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();
    Event::assertDispatched(PasswordConfirmed::class, fn ($e) => $e->user->is($user));
});

it('writes password confirmations to the audit log, without the password', function () {
    $this->loginWithSession($user = $this->makeUser());

    $this->postJson(route('mfa.password.confirm'), ['password' => 'wrong-secret'])->assertStatus(422);
    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();

    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
    $this->travel(4)->hours();
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423);

    expect(MfaAuditLog::orderBy('id')->get(['user_id', 'event', 'reason', 'flow_id'])->map->only('user_id', 'event', 'reason')->all())->toBe([
        ['user_id' => $user->id, 'event' => 'password_confirmation_failed', 'reason' => 'invalid_password'],
        ['user_id' => $user->id, 'event' => 'password_confirmed', 'reason' => null],
        ['user_id' => $user->id, 'event' => 'factor_enrollment_started', 'reason' => null],
        ['user_id' => $user->id, 'event' => 'password_confirmation_required', 'reason' => null],
    ])
        ->and(MfaAuditLog::whereNull('flow_id')->count())->toBe(0)
        ->and(json_encode(MfaAuditLog::all()->toArray()))->not->toContain('wrong-secret');
});

it('validates the password field', function () {
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.password.confirm'))->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson(route('mfa.password.confirm'), ['password' => ['x']])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson(route('mfa.password.confirm'), ['password' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('checks the password of the user who logged in to the session, not of a user swapped in with Auth::setUser()', function () {
    // clone-voice / podcast-flow style impersonation, on the MFA routes too.
    config(['mfa.routes.middleware' => ['web', 'auth', HandleImpersonation::class]]);
    require __DIR__.'/../../routes/mfa.php';
    app('router')->getRoutes()->refreshNameLookups();
    $target = $this->makeUser(['password' => 'target-password']);
    DB::table('users')->where('id', $target->id)->update(['password' => '']);
    $this->loginWithSession($this->makeUser())->withSession(['impersonated_id' => $target->id]);

    // Asked although Auth::user() (the target) has no password: the admin is the session user.
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423);
    $this->postJson(route('mfa.password.confirm'), ['password' => 'target-password'])->assertStatus(422);
    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();
});

it('asks again after auth.password_timeout', function () {
    $this->freezeSecond();
    config(['auth.password_timeout' => 600]);
    $this->loginWithSession($this->makeUser());
    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();

    $this->travel(600)->seconds();
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();

    $this->travel(1)->seconds();
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423);
});

it('reads auth.password_timeout as is, with no default of its own', function () {
    $this->freezeSecond();
    config(['auth.password_timeout' => null]);
    $this->loginWithSession($this->makeUser())->withConfirmedPassword();

    // Unset means no grace period (mfa:doctor fails on it): only this very second counts.
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
    $this->travel(1)->seconds();
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423);
});

it('accepts a confirmation from the app\'s own password.confirm page (same session key)', function () {
    $this->loginWithSession($this->makeUser());
    session(['auth.password_confirmed_at' => now()->getTimestamp()]);

    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
});

it('rate-limits password attempts per account, counting before checking', function () {
    $this->freezeSecond();
    Event::fake([PasswordConfirmationFailed::class]);
    $this->loginWithSession($user = $this->makeUser());

    foreach (range(1, 5) as $_) {
        $this->postJson(route('mfa.password.confirm'), ['password' => 'wrong'])->assertStatus(422);
    }

    // Even the right password is refused while limited.
    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])
        ->assertStatus(429)
        ->assertJson(['errors' => ['password' => ['Too many attempts. Please try again later.']], 'retry_after' => 60]);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
    Event::assertDispatched(PasswordConfirmationFailed::class, fn ($e) => $e->reason === FailureReason::RateLimited);

    $this->travel(61)->seconds();
    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();
});

it('clears the attempt count on success and caps attempts per day', function () {
    $this->freezeSecond();
    config(['mfa.rate_limit.password_per_minute' => 100, 'mfa.rate_limit.password_per_day' => 3]);
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.password.confirm'), ['password' => 'wrong'])->assertStatus(422);
    $this->postJson(route('mfa.password.confirm'), ['password' => 'wrong'])->assertStatus(422);
    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();

    foreach (range(1, 3) as $_) {
        $this->postJson(route('mfa.password.confirm'), ['password' => 'wrong'])->assertStatus(422);
    }
    $this->postJson(route('mfa.password.confirm'), ['password' => 'wrong'])->assertStatus(429)->assertJsonPath('retry_after', 86400);
});

it('never asks users whose stored password is empty', function () {
    $user = $this->makeUser();
    DB::table('users')->where('id', $user->id)->update(['password' => '']);
    $this->loginWithSession($user->fresh());

    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
    // Nothing to confirm against: the prompt can't be passed with any password.
    $this->postJson(route('mfa.password.confirm'), ['password' => ''])->assertStatus(422);
});

it('lets a password confirmation policy exempt users (e.g. social logins)', function () {
    config(['mfa.routes.password_confirmation_policy' => ExemptSocialLogins::class]);

    $this->loginWithSession($this->makeUser(['name' => 'Google user']));
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();

    $this->freshGuards()->loginWithSession($this->makeUser());
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423);
});

it('asks nobody when routes.password_confirmation is false', function () {
    config(['mfa.routes.password_confirmation' => false]);
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
});

it('answers Inertia with a validation error the page can act on, and redirects to settings once confirmed', function () {
    config(['mfa.ui.driver' => 'inertia']);
    $this->loginWithSession($this->makeUser());
    $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

    $this->from(route('mfa.settings'))->post(route('mfa.factors.store'), ['type' => 'totp'], $inertia)
        ->assertRedirect(route('mfa.settings'))
        ->assertSessionHasErrors(['password_confirmation_required' => 'Please confirm your password to continue.']);
    expect(MfaFactor::count())->toBe(0);

    $this->from(route('mfa.settings'))->post(route('mfa.password.confirm'), ['password' => 'wrong'], $inertia)
        ->assertRedirect(route('mfa.settings'))
        ->assertSessionHasErrors(['password' => 'The provided password is incorrect.']);

    $this->post(route('mfa.password.confirm'), ['password' => 'password'], $inertia)
        ->assertRedirect(route('mfa.settings'))
        ->assertSessionHas('mfa.status', 'password-confirmed');
});

it('gives the password prompt its own countdown, never the code-resend one', function () {
    $this->freezeSecond();
    config(['mfa.ui.driver' => 'inertia', 'mfa.rate_limit.password_per_minute' => 1]);
    $this->loginWithSession($this->makeUser());
    $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

    $this->from(route('mfa.settings'))->post(route('mfa.password.confirm'), ['password' => 'wrong'], $inertia);
    $this->from(route('mfa.settings'))->post(route('mfa.password.confirm'), ['password' => 'wrong'], $inertia)
        ->assertSessionHasErrors(['password' => 'Too many attempts. Please try again later.'])
        ->assertSessionHas(UiResponse::PASSWORD_RETRY_AFTER, 60)
        ->assertSessionMissing('mfa.retry_after');

    config(['mfa.ui.driver' => 'json']);
    $this->getJson(route('mfa.settings'))->assertJson(['passwordRetryAfter' => 60, 'retryAfter' => null]);
});

it('lets an enforced user who must enroll confirm their password', function () {
    Mfa::fakeCodes();
    config(['mfa.enforcement.policy' => EnforceForAdmins::class]);
    $this->loginWithSession($this->makeUser(['is_admin' => true]));

    $this->get('/dashboard')->assertRedirect(route('mfa.settings'));
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423);
    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();
    // Then proof of ownership beyond the password (enrollment_verification).
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertStatus(423)->assertJson(['error' => 'enrollment_verification_required']);
    $this->postJson(route('mfa.enrollment-verification.send'))->assertOk();
    $this->postJson(route('mfa.enrollment-verification.verify'), ['code' => '123456'])->assertOk();
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
});

it('is unreachable for a user who has factors but has not passed MFA', function () {
    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);

    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertForbidden()->assertJson(['error' => 'mfa_required']);
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

it('runs the app\'s confirm_middleware first', function () {
    config(['mfa.routes.confirm_middleware' => ['password.confirm']]);
    require __DIR__.'/../../routes/mfa.php';
    app('router')->getRoutes()->refreshNameLookups();
    $this->loginWithSession($this->makeUser());

    $this->post(route('mfa.factors.store'), ['type' => 'totp'])->assertRedirect(route('password.confirm'));

    // The app's page confirmed it: MFA doesn't ask again.
    session(['auth.password_confirmed_at' => now()->getTimestamp()]);
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
});

it('tells the frontend that factor changes may ask for the password', function () {
    expect(Mfa::context()->passwordConfirmation)->toBeTrue();

    config(['mfa.routes.password_confirmation' => false]);
    expect(Mfa::context()->passwordConfirmation)->toBeFalse();

    config(['mfa.routes.confirm_middleware' => ['password.confirm']]);
    expect(Mfa::context()->passwordConfirmation)->toBeTrue();
});

it('tells the settings page whether a change would ask for the password now', function () {
    $this->freezeSecond();
    config(['auth.password_timeout' => 600]);
    $this->loginWithSession($this->makeUser());

    $this->getJson(route('mfa.settings'))->assertJsonPath('passwordConfirmationRequired', true);

    $this->postJson(route('mfa.password.confirm'), ['password' => 'password'])->assertOk();
    $this->getJson(route('mfa.settings'))->assertJsonPath('passwordConfirmationRequired', false);

    $this->travel(601)->seconds();
    $this->getJson(route('mfa.settings'))->assertJsonPath('passwordConfirmationRequired', true);

    config(['mfa.routes.password_confirmation' => false]);
    $this->getJson(route('mfa.settings'))->assertJsonPath('passwordConfirmationRequired', false);
});
