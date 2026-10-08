<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ChallengeDeliveryFailed;
use StrontiumCorp\LaravelMfa\Events\RecoveryCodeUsed;
use StrontiumCorp\LaravelMfa\Events\VerificationFailed;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Notifications\OtpCodeNotification;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

it('lists the user\'s factors without leaking secrets', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);

    $response = $this->loginWithSession($user)->getJson(route('mfa.challenge'))->assertOk();

    $response->assertJsonPath('factors.0.destination', '+*******0100')
        ->assertJsonPath('defaultFactorId', $factor->id);

    expect($response->getContent())->not->toContain('+15555550100');
});

it('points "Sign out" at the app\'s named logout route, wherever it lives', function () {
    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);

    expect($this->getJson(route('mfa.challenge'))->json('urls.logout'))->toBe(url('/logout'));

    // artistly: logout is POST /admin/logout
    Route::middleware('web')->post('/admin/logout', fn () => 'bye')->name('admin.logout');
    app('router')->getRoutes()->refreshNameLookups();
    config(['mfa.routes.logout_route' => 'admin.logout']);

    expect($this->getJson(route('mfa.challenge'))->json('urls.logout'))->toBe(url('/admin/logout'));
});

it('hides "Sign out" when the logout route does not exist', function () {
    config(['mfa.routes.logout_route' => 'nope']);
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->getJson(route('mfa.challenge'))->assertOk()->assertJsonPath('urls.logout', null);
});

it('renders the Inertia page', function () {
    config(['mfa.ui.driver' => 'inertia']);
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)
        ->get(route('mfa.challenge'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'mfa/challenge')
        ->assertJsonPath('props.urls.verify', route('mfa.challenge.verify'));
});

it('completes a TOTP challenge and returns to the intended page', function () {
    config(['mfa.ui.driver' => 'inertia']);
    Event::fake([VerificationSucceeded::class]);
    [$user, $factor] = $this->userWithFactor(FactorType::Totp);

    $this->loginWithSession($user)->get('/dashboard');
    $sessionId = session()->getId();

    $this->post(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
        ->assertRedirect('/dashboard');

    expect(session()->getId())->not->toBe($sessionId)       // fixation protection
        ->and(session()->has('mfa.flow_id'))->toBeFalse();

    $this->get('/dashboard')->assertOk();
    Event::assertDispatched(VerificationSucceeded::class, fn ($e) => $e->factorType === FactorType::Totp);
});

it('returns JSON from the json UI driver', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Totp);

    $this->loginWithSession($user)->get('/dashboard');

    $this->post(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
        ->assertOk()
        ->assertJson(['status' => 'verified', 'redirect' => url('/dashboard')]);
});

it('rejects a wrong code with a validation error and an event', function () {
    Event::fake([VerificationFailed::class]);
    [$user, $factor] = $this->userWithFactor();

    $this->loginWithSession($user)
        ->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    Event::assertDispatched(VerificationFailed::class, fn ($e) => $e->reason->value === 'invalid_code');
});

it('delivers and verifies an email code', function () {
    Notification::fake();
    Mfa::fakeCodes('482913');
    [$user, $factor] = $this->userWithFactor(FactorType::Email);

    $this->loginWithSession($user)->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();

    Notification::assertSentOnDemand(OtpCodeNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $user->email && $n->code === '482913');

    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '482913'])->assertOk();
    $this->get('/dashboard')->assertOk();
});

it('delivers and verifies an SMS code', function () {
    $sms = Mfa::fakeSms();
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);

    $this->loginWithSession($user)->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();

    $sms->assertSentTo('+15555550100');
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $sms->lastCodeFor('+15555550100')])->assertOk();
});

it('applies the resend cooldown', function () {
    Mfa::fakeSms();
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    $this->loginWithSession($user);

    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertStatus(429);
});

it('caps sends per hour across factors', function () {
    config(['mfa.rate_limit.send_per_hour' => 2, 'mfa.factors.sms.resend_cooldown' => 0]);
    $sms = Mfa::fakeSms();
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    $this->loginWithSession($user);

    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertStatus(429);

    $sms->assertSentTo('+15555550100', 2);
});

it('rate limits verification attempts', function () {
    [$user, $factor] = $this->userWithFactor();
    $this->loginWithSession($user);

    foreach (range(1, 5) as $_) {
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000'])->assertUnprocessable();
    }

    // Even the right code is refused while limited.
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
        ->assertStatus(429);
});

it('reports delivery failures and lets the user retry immediately', function () {
    Event::fake([ChallengeDeliveryFailed::class]);
    $sms = Mfa::fakeSms()->failWith('Twilio is down');
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    $this->loginWithSession($user);

    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'We could not send your code. Please try again.');

    Event::assertDispatched(ChallengeDeliveryFailed::class, fn ($e) => str_contains($e->context['error'], 'Twilio is down'));

    // The failed code was discarded, so no cooldown applies.
    Mfa::fakeSms();
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
});

it('accepts a recovery code once', function () {
    Event::fake([RecoveryCodeUsed::class]);
    [$user] = $this->userWithFactor();
    $code = app(RecoveryCodes::class)->generate($user)[0];
    $this->loginWithSession($user);

    $this->postJson(route('mfa.challenge.recover'), ['code' => $code])->assertOk()->assertJson(['remaining' => 9]);
    Event::assertDispatched(RecoveryCodeUsed::class, fn ($e) => $e->context['remaining'] === 9);

    $this->freshGuards()->post('/logout');
    $this->loginWithSession($user)->postJson(route('mfa.challenge.recover'), ['code' => $code])->assertUnprocessable();
});

it('will not verify against another user\'s factor', function () {
    [, $otherFactor] = $this->userWithFactor();
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)
        ->postJson(route('mfa.challenge.verify'), ['factor_id' => $otherFactor->id, 'code' => $this->currentTotpCode($otherFactor)])
        ->assertUnprocessable();
});

it('ignores factors whose type has been disabled in config', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    config(['mfa.factors.sms.enabled' => false]);
    Mfa::forgetCachedState($user);

    // With its only factor type disabled the user is no longer challenged.
    $this->loginWithSession($user)->get('/dashboard')->assertOk();
});
