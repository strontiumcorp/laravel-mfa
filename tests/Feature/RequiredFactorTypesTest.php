<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\EnrollmentRequired;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

// enforcement.required_types (default ['totp']): what enforced users must set
// up and sign in with. Their other factors don't count.

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

it('accepts any of several required types', function () {
    config(['mfa.enforcement.required_types' => ['totp', 'sms']]);
    [$admin] = $this->userWithFactor(FactorType::Sms, ['is_admin' => true]);
    $this->createMfaFactor($admin, FactorType::Email);

    expect(Mfa::mustEnroll($admin))->toBeFalse()
        ->and(array_map(fn ($t) => $t->value, Mfa::challengeTypes($admin)))->toBe(['sms']);
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
