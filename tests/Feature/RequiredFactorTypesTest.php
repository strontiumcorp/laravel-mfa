<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ChallengeStepPassed;
use StrontiumCorp\LaravelMfa\Events\EnrollmentRequired;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Support\ChallengeSteps;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

// enforcement.required_types (default ['totp']): what enforced users must set
// up and sign in with, every one of them. Their other factors don't count.

beforeEach(fn () => config(['mfa.enforcement.policy' => EnforceForAdmins::class]));

function requiredTypesVerifyByEmail($test, MfaFactor $factor): void
{
    Mfa::fakeCodes('482913');
    $test->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
    $test->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '482913'])->assertOk();
}

function requiredTypesAddTotp($test): MfaFactor
{
    $id = $test->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk()->json('factor.id');
    $factor = MfaFactor::findOrFail($id);
    $test->postJson(route('mfa.factors.confirm', $id), ['code' => $test->currentTotpCode($factor)])->assertOk();

    return $factor;
}

it('lets an enforced user with only email verify with it, then holds them until they add TOTP', function () {
    Event::fake([EnrollmentRequired::class]);
    [$admin, $email] = $this->userWithFactor(FactorType::Email, ['is_admin' => true]);
    $this->loginWithSession($admin);

    // No required factor yet: the factors they have are offered, so a stolen
    // password alone still can't get in or add a factor.
    $this->getJson(route('mfa.challenge'))->assertOk()->assertJsonPath('factors.*.type', ['email']);
    $this->getJson(route('mfa.settings'))->assertForbidden();
    requiredTypesVerifyByEmail($this, $email);

    $this->get('/dashboard')->assertRedirect(route('mfa.settings'));
    $this->getJson('/dashboard')->assertForbidden()->assertJson(['error' => 'mfa_enrollment_required']);
    Event::assertDispatched(EnrollmentRequired::class);
    $this->getJson(route('mfa.settings'))->assertOk()->assertJson([
        'mustEnroll' => true,
        'requiredTypes' => [['type' => 'totp', 'label' => 'Authenticator app']],
    ]);
    expect(Mfa::context(request()->setLaravelSession(session()->driver()))->user['mustEnroll'])->toBeTrue();

    requiredTypesAddTotp($this);

    $this->get('/dashboard')->assertOk();
    $this->getJson(route('mfa.settings'))->assertJson(['mustEnroll' => false]);
});

it('offers an enforced user only their required factors at the challenge, recovery codes aside', function () {
    [$admin, $totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
    $email = $this->createMfaFactor($admin, FactorType::Email);
    $recovery = app(RecoveryCodes::class)->generate($admin)[0];
    $this->loginWithSession($admin);

    $this->getJson(route('mfa.challenge'))->assertOk()
        ->assertJsonPath('factors.*.type', ['totp'])
        ->assertJsonPath('defaultFactorId', $totp->id);

    // The server refuses the email factor too, not just the page.
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $email->id])->assertUnprocessable();
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $email->id, 'code' => '000000'])->assertUnprocessable();

    $this->postJson(route('mfa.challenge.recover'), ['code' => $recovery])->assertOk();
    $this->get('/dashboard')->assertOk();
});

it('sends an enforced user back to enroll after they remove their last required factor', function () {
    [$admin, $totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
    $this->createMfaFactor($admin, FactorType::Email);
    $this->actingAsMfaVerified($admin);
    $this->get('/dashboard')->assertOk();

    $this->deleteJson(route('mfa.factors.destroy', $totp->id))->assertOk();

    $this->get('/dashboard')->assertRedirect(route('mfa.settings'));
});

it('leaves users who are not enforced alone', function () {
    [$user, $email] = $this->userWithFactor(FactorType::Email);
    $this->createMfaFactor($user, FactorType::Totp);
    $this->loginWithSession($user);

    $this->getJson(route('mfa.challenge'))->assertJsonPath('factors.*.type', fn ($types) => count($types) === 2);
    requiredTypesVerifyByEmail($this, $email);

    $this->get('/dashboard')->assertOk();
    $this->getJson(route('mfa.settings'))->assertJson(['mustEnroll' => false, 'requiredTypes' => []]);
});

it('accepts any factor when required_types is empty or none of them is enabled', function (array $config) {
    config($config);
    [$admin, $email] = $this->userWithFactor(FactorType::Email, ['is_admin' => true]);
    $this->loginWithSession($admin);

    requiredTypesVerifyByEmail($this, $email);

    $this->get('/dashboard')->assertOk();
    expect(Mfa::mustEnroll($admin))->toBeFalse();
})->with([
    'empty' => [['mfa.enforcement.required_types' => []]],
    'TOTP disabled' => [['mfa.factors.totp.enabled' => false]],
]);

it('requires every one of several required types', function () {
    config(['mfa.enforcement.required_types' => ['totp', 'sms']]);
    [$admin] = $this->userWithFactor(FactorType::Sms, ['is_admin' => true]);
    $this->createMfaFactor($admin, FactorType::Email);

    // Holds sms but not totp: still enrolling; challenged with sms alone meanwhile.
    expect(Mfa::mustEnroll($admin))->toBeTrue()
        ->and(Mfa::challengeRequirement($admin))->toEqual(['types' => [FactorType::Sms], 'all' => true]);

    $this->createMfaFactor($admin, FactorType::Totp);
    expect(Mfa::mustEnroll($admin))->toBeFalse()
        ->and(Mfa::challengeRequirement($admin)['types'])->toEqualCanonicalizing([FactorType::Totp, FactorType::Sms]);
});

describe('a challenge with several required types', function () {
    beforeEach(function () {
        // Before anything resolves the Mfa singleton, which keeps the dispatcher it was built with.
        Event::fake([ChallengeStepPassed::class, VerificationSucceeded::class]);
        config(['mfa.enforcement.required_types' => ['totp', 'email']]);
        [$this->admin, $this->totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
        $this->email = $this->createMfaFactor($this->admin, FactorType::Email);
        $this->createMfaFactor($this->admin, FactorType::Sms); // not required: never offered
        $this->loginWithSession($this->admin);
    });

    it('asks for each one, a code each, before the session is verified', function () {
        $this->getJson(route('mfa.challenge'))->assertJsonPath('factors.*.type', ['totp', 'email'])
            ->assertJsonPath('steps', ['total' => 2, 'passed' => []]);

        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $this->totp->id, 'code' => $this->currentTotpCode($this->totp)])
            ->assertOk()->assertExactJson(['status' => 'factor-verified', 'remaining' => ['email']]);
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
        Event::assertDispatched(ChallengeStepPassed::class, fn ($e) => $e->factorType === FactorType::Totp && $e->context['remaining'] === ['email']);
        Event::assertNotDispatched(VerificationSucceeded::class);

        $this->getJson(route('mfa.challenge'))->assertJsonPath('factors.*.type', ['email'])
            ->assertJsonPath('steps', ['total' => 2, 'passed' => ['totp']]);
        requiredTypesVerifyByEmail($this, $this->email);

        $this->get('/dashboard')->assertOk();
        Event::assertDispatched(VerificationSucceeded::class);
    });

    it('takes them in any order, and never a type that is not required', function () {
        requiredTypesVerifyByEmail($this, $this->email);
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));

        $sms = $this->admin->mfaFactors()->where('type', 'sms')->sole();
        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $sms->id])->assertUnprocessable();

        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $this->totp->id, 'code' => $this->currentTotpCode($this->totp)])
            ->assertOk()->assertJsonPath('status', 'verified');
        $this->get('/dashboard')->assertOk();
    });

    it('starts over when the next code comes more than 10 minutes later, or after a logout', function () {
        $this->freezeSecond();
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $this->totp->id, 'code' => $this->currentTotpCode($this->totp)])->assertOk();

        $this->travel(601)->seconds();
        $this->getJson(route('mfa.challenge'))->assertJsonPath('steps.passed', []);

        $this->travel(31)->seconds();
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $this->totp->id, 'code' => $this->currentTotpCode($this->totp)])->assertOk();
        $this->post('/logout');
        Auth::forgetGuards();
        $this->loginWithSession($this->admin)->getJson(route('mfa.challenge'))->assertJsonPath('steps.passed', []);
    });

    it('lets one recovery code pass the whole challenge, as before', function () {
        $code = app(RecoveryCodes::class)->generate($this->admin)[0];

        $this->postJson(route('mfa.challenge.recover'), ['code' => $code])->assertOk();
        $this->get('/dashboard')->assertOk();
    });
});

it('offers an enforced user only the required types in settings, and refuses the rest', function () {
    config(['mfa.enforcement.required_types' => ['totp', 'email']]);
    $admin = $this->makeUser(['is_admin' => true]);
    $this->actingAsMfaVerified($admin)->withEnrollmentVerified($admin);

    $this->getJson(route('mfa.settings'))->assertJsonPath('availableTypes.*.type', ['totp', 'email']);
    $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+15555550100'])
        ->assertUnprocessable()->assertJsonValidationErrors(['type' => 'Your account can only use Authenticator app and Email.']);

    // Not enforced: every enabled type.
    $user = $this->makeUser();
    Auth::forgetGuards();
    $this->flushSession();
    $this->actingAsMfaVerified($user)->withEnrollmentVerified($user);
    $this->getJson(route('mfa.settings'))->assertJsonPath('availableTypes.*.type', ['totp', 'email', 'sms']);
});

it('refuses to finish a setup of a type the user may no longer add', function () {
    $admin = $this->makeUser(['is_admin' => false]);
    $this->actingAsMfaVerified($admin)->withEnrollmentVerified($admin);
    Mfa::fakeCodes('482913');
    $id = $this->postJson(route('mfa.factors.store'), ['type' => 'email'])->assertOk()->json('factor.id');

    $admin->forceFill(['is_admin' => true])->save(); // enforced from now on (required: totp)
    $this->postJson(route('mfa.factors.confirm', $id), ['code' => '482913'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Your account can only use Authenticator app.']);
});

it('enforces roles and a policy together', function () {
    config(['mfa.enforcement.roles' => ['support']]);

    expect(Mfa::mustEnroll($this->makeUser(['is_admin' => true])))->toBeTrue()
        ->and(Mfa::mustEnroll($this->makeUser()->forceFill(['role' => 'support'])))->toBeTrue()
        ->and(Mfa::mustEnroll($this->makeUser()))->toBeFalse();
});

it('still honours the v0.1 "enforce" key, and mfa:doctor says to move it', function () {
    config(['mfa.enforcement.policy' => null, 'mfa.enforce' => ['admin'], 'session.driver' => 'database']);

    expect(Mfa::mustEnroll($this->makeUser()->forceFill(['role' => 'admin'])))->toBeTrue();
    $this->artisan('mfa:doctor')->expectsOutputToContain('"enforce" moved to "enforcement.roles"');

    config(['mfa.enforce' => EnforceForAdmins::class]);
    expect(Mfa::mustEnroll($this->makeUser(['is_admin' => true])))->toBeTrue();
});

it('makes mfa:doctor warn when enforced users could enroll with only the password', function () {
    config(['session.driver' => 'database', 'mfa.enforcement.roles' => ['admin']]);
    $this->artisan('mfa:doctor')->doesntExpectOutputToContain('Enrollment verification is off');

    config(['mfa.enrollment_verification.required_for' => null]);
    $this->artisan('mfa:doctor')->expectsOutputToContain('Enrollment verification is off');

    config(['mfa.enrollment_verification.required_for' => 'enforced', 'mfa.enrollment_verification.email' => false]);
    $this->artisan('mfa:doctor')->expectsOutputToContain('administrator links only');
});

it('makes mfa:doctor fail on an unknown required type and warn when none is enabled', function () {
    config(['session.driver' => 'database']);

    config(['mfa.enforcement.required_types' => ['totp', 'passkey']]);
    $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('enforcement.required_types');

    config(['mfa.enforcement.required_types' => ['sms'], 'mfa.factors.sms.enabled' => false]);
    $this->artisan('mfa:doctor')->expectsOutputToContain('none of enforcement.required_types is enabled');
});

it('makes mfa:doctor say the old "enforce" key is ignored once the new keys are set', function () {
    config(['session.driver' => 'database', 'mfa.enforce' => ['support']]);

    $this->artisan('mfa:doctor')->expectsOutputToContain('"enforce" is ignored');
    expect(Mfa::mustEnroll($this->makeUser()->forceFill(['role' => 'support'])))->toBeFalse();
});

it('costs no MFA query once an enforced user with a required factor is verified', function () {
    [$admin, $totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
    $this->loginWithSession($admin)
        ->postJson(route('mfa.challenge.verify'), ['factor_id' => $totp->id, 'code' => $this->currentTotpCode($totp)])
        ->assertOk();

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'mfa_')) {
            $queries[] = $q->sql;
        }
    });
    $this->get('/dashboard')->assertOk();

    expect($queries)->toBeEmpty();
});

it('summarises enforcement in php artisan about', function () {
    config(['mfa.enforcement.roles' => ['support']]);
    $this->artisan('about', ['--only' => 'mfa'])->expectsOutputToContain('roles: support + '.EnforceForAdmins::class.' (requires totp)');

    config(['mfa.enforcement.roles' => [], 'mfa.enforcement.policy' => null, 'mfa.enforcement.required_types' => []]);
    $this->artisan('about', ['--only' => 'mfa'])->expectsOutputToContain('opt-in');
});

describe('second review fixes', function () {
    beforeEach(function () {
        config(['mfa.enforcement.required_types' => ['totp', 'email']]);
        [$this->admin, $this->totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
        $this->email = $this->createMfaFactor($this->admin, FactorType::Email);
    });

    it('keeps "Verify now" going through every step, and starts a new window at the end', function () {
        $this->freezeSecond();
        config(['mfa.lifetime.profiles.enforced.idle' => null]);
        $this->actingAsMfaVerified($this->admin);
        $this->get('/dashboard')->assertOk();
        $this->travel(220)->minutes();
        $this->withHeader('referer', url('/dashboard'))->getJson(route('mfa.challenge', ['renew' => 1]))->assertOk()->assertJsonPath('renew', true);

        config(['mfa.ui.driver' => 'inertia']); // a page, not JSON: where does the next step go?
        $this->post(route('mfa.challenge.verify'), ['factor_id' => $this->totp->id, 'code' => $this->currentTotpCode($this->totp)])
            ->assertRedirect(route('mfa.challenge', ['renew' => 1]));
        config(['mfa.ui.driver' => 'json']);
        $this->getJson(route('mfa.challenge', ['renew' => 1]))->assertOk()->assertJsonPath('factors.*.type', ['email']);

        requiredTypesVerifyByEmail($this, $this->email);
        $this->travel(239)->minutes();
        $this->get('/dashboard')->assertOk();
    });

    it('drops passed steps when the verification is revoked, and on a new login', function () {
        $this->loginWithSession($this->admin);
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $this->totp->id, 'code' => $this->currentTotpCode($this->totp)])->assertOk();
        expect(session()->has(ChallengeSteps::SESSION_KEY.'.web.'.$this->admin->id))->toBeTrue();

        Auth::guard('web')->login($this->admin); // a new login in the same session
        expect(session()->has(ChallengeSteps::SESSION_KEY.'.web.'.$this->admin->id))->toBeFalse();
    });

    it('lets each passed step count for 10 minutes from when it was passed, not from the latest one', function () {
        config(['mfa.enforcement.required_types' => ['totp', 'email', 'sms']]);
        $this->createMfaFactor($this->admin, FactorType::Sms);
        $this->freezeSecond();
        $this->loginWithSession($this->admin);

        requiredTypesVerifyByEmail($this, $this->email);
        $this->travel(9)->minutes();
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $this->totp->id, 'code' => $this->currentTotpCode($this->totp)])->assertOk();
        $this->travel(2)->minutes();

        $this->getJson(route('mfa.challenge'))->assertJsonPath('steps.passed', ['totp']);
    });
});

it('stops a remember-me cookie from logging the user back in after a reset', function () {
    [$user] = $this->userWithFactor();
    $user->forceFill(['remember_token' => 'remember-me-token'])->save();
    $this->travel(1)->seconds();

    Mfa::reset($user);
    $this->travel(1)->seconds();

    // The cookie's token no longer finds the user. (Checked directly: Laravel 12.69.0's
    // guard throws a TypeError for a cookie whose token is gone, instead of ignoring it.)
    expect(auth()->guard('web')->getProvider()->retrieveByToken($user->id, 'remember-me-token'))->toBeNull()
        ->and($user->fresh()->remember_token)->not->toBe('remember-me-token');
});

it('forgets passed steps when a session is revoked', function () {
    config(['mfa.enforcement.required_types' => ['totp', 'email']]);
    [$admin, $totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
    $this->createMfaFactor($admin, FactorType::Email);
    $this->actingAsMfaVerified($admin);
    ChallengeSteps::pass(session()->driver(), 'web', $admin->id, FactorType::Totp);
    $this->travel(1)->seconds();

    Mfa::revokeVerifications($admin);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    expect(session()->has(ChallengeSteps::SESSION_KEY.'.web.'.$admin->id))->toBeFalse();
});

it("doesn't reset the verify limits on a step that leaves another to go", function () {
    config(['mfa.enforcement.required_types' => ['totp', 'email'], 'mfa.rate_limit.verify_per_day' => 3]);
    [$admin, $totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
    $email = $this->createMfaFactor($admin, FactorType::Email);
    $this->loginWithSession($admin);

    foreach ([1, 2] as $_) {
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $totp->id, 'code' => '000000'])->assertUnprocessable();
    }
    requiredTypesVerifyByEmail($this, $email); // the third attempt, a pass: not the end of the challenge

    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $totp->id, 'code' => '000000'])->assertStatus(429);
});

it('clears the verify limits once the last step passes', function () {
    config(['mfa.enforcement.required_types' => ['totp', 'email'], 'mfa.rate_limit.verify_per_day' => 3]);
    [$admin, $totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
    $email = $this->createMfaFactor($admin, FactorType::Email);
    $this->loginWithSession($admin);

    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $totp->id, 'code' => '000000'])->assertUnprocessable();
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $totp->id, 'code' => $this->currentTotpCode($totp)])->assertOk();
    requiredTypesVerifyByEmail($this, $email);
    $this->post('/logout');
    Auth::forgetGuards();
    $this->travel(31)->seconds();

    $this->loginWithSession($admin);
    foreach ([1, 2] as $_) {
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $totp->id, 'code' => '000000'])->assertUnprocessable();
    }
});

it('still clears the verify limits when a new method is confirmed', function () {
    config(['mfa.rate_limit.verify_per_day' => 3]);
    [$user] = $this->userWithFactor(FactorType::Email);
    $this->actingAsMfaVerified($user);

    $id = $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk()->json('factor.id');
    $factor = MfaFactor::findOrFail($id);
    foreach ([1, 2] as $_) {
        $this->postJson(route('mfa.factors.confirm', $id), ['code' => '000000'])->assertUnprocessable();
    }
    $this->postJson(route('mfa.factors.confirm', $id), ['code' => $this->currentTotpCode($factor)])->assertOk();

    Mfa::fakeCodes('482913');
    $sms = $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+15555550101'])->assertOk()->json('factor.id');
    foreach ([1, 2] as $_) {
        $this->postJson(route('mfa.factors.confirm', $sms), ['code' => '000000'])->assertUnprocessable();
    }
});

it('offers the held required types again when every one left has already passed', function () {
    config(['mfa.enforcement.required_types' => ['totp', 'email']]);
    [$admin, $totp] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
    $email = $this->createMfaFactor($admin, FactorType::Email);
    $this->loginWithSession($admin);
    requiredTypesVerifyByEmail($this, $email);

    $totp->delete(); // removed by an administrator meanwhile

    $this->getJson(route('mfa.challenge'))->assertJsonPath('factors.*.type', ['email']);
    $this->travel(121)->seconds();
    requiredTypesVerifyByEmail($this, $email);
    $this->get('/dashboard')->assertRedirect(route('mfa.settings')); // verified; must add an authenticator app again
});
