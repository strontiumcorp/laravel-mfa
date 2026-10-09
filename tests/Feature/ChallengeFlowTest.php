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

it('lets an unverified user sign out through a custom logout route', function () {
    Route::middleware('web')->post('/admin/logout', fn () => 'bye')->name('admin.logout');
    app('router')->getRoutes()->refreshNameLookups();
    config(['mfa.routes.logout_route' => 'admin.logout']);
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->post('/admin/logout')->assertOk()->assertSee('bye');
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

it('leaves the challenge with a full page visit for Inertia, since the target may not be an Inertia page', function () {
    config(['mfa.ui.driver' => 'inertia']);
    [$user, $factor] = $this->userWithFactor(FactorType::Totp);
    $this->loginWithSession($user)->get('/dashboard');

    $this->post(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/dashboard'));

    $this->get('/dashboard')->assertOk();
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

it('tells the page how many digits each factor\'s codes have', function () {
    config(['mfa.factors.email.length' => 8, 'mfa.factors.sms.length' => 7]);
    [$user, $totp] = $this->userWithFactor(FactorType::Totp);
    $email = $this->createMfaFactor($user, FactorType::Email);
    $sms = $this->createMfaFactor($user, FactorType::Sms);

    $factors = collect($this->loginWithSession($user)->getJson(route('mfa.challenge'))->assertOk()->json('factors'))->keyBy('id');

    // Authenticator codes are always 6 digits; email/SMS follow factors.{type}.length.
    expect($factors[$totp->id]['code_length'])->toBe(6)
        ->and($factors[$email->id]['code_length'])->toBe(8)
        ->and($factors[$sms->id]['code_length'])->toBe(7);
});

describe('send state on the challenge page', function () {
    // The page shows each email/SMS factor's outstanding code and cooldown, so
    // a refresh keeps the countdown (and the page doesn't send a second code).
    $sendState = function ($test): array {
        $page = $test->getJson(route('mfa.challenge'))->assertOk();

        return [$page->json('factors.0.code_sent'), $page->json('factors.0.retry_after')];
    };

    it('keeps the remaining resend cooldown after a refresh', function () {
        config(['mfa.ui.driver' => 'inertia']);
        $this->freezeSecond(); // exact waits
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->post(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertRedirect();
        $this->get(route('mfa.challenge'), ['X-Inertia' => 'true'])->assertJsonPath('props.retryAfter', 120); // the redirect, with the flash
        $this->travel(30)->seconds();

        // A refresh: no flash any more, but the page still knows the wait.
        $this->get(route('mfa.challenge'), ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.retryAfter', null)
            ->assertJsonPath('props.factors.0.code_sent', true)
            ->assertJsonPath('props.factors.0.retry_after', 90);
    });

    it('reports no code and no wait before anything was sent', function () use ($sendState) {
        [$user] = $this->userWithFactor(FactorType::Email);
        $this->loginWithSession($user);

        expect($sendState($this))->toBe([false, null]);
    });

    it('reports a code still out, with no wait, once the cooldown has passed', function () use ($sendState) {
        Notification::fake();
        [$user, $factor] = $this->userWithFactor(FactorType::Email);
        $this->loginWithSession($user);

        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
        $this->travel(121)->seconds();

        expect($sendState($this))->toBe([true, null]);
    });

    it('reports nothing outstanding once the code has expired', function () use ($sendState) {
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
        $this->travel(601)->seconds();

        expect($sendState($this))->toBe([false, null]);
    });

    it('reports nothing outstanding once the code was burned', function () use ($sendState) {
        config(['mfa.factors.sms.max_attempts' => 1]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000'])->assertUnprocessable();

        expect($sendState($this))->toBe([false, null]);
    });

    it('follows the cooldown curve: a second send shows the longer wait', function () use ($sendState) {
        $this->freezeSecond();
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertJsonPath('retry_after', 120);
        $this->travel(121)->seconds();
        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertJsonPath('retry_after', 240);
        $this->travel(40)->seconds();

        expect($sendState($this))->toBe([true, 200]);
        // And the server agrees with what the page shows.
        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertStatus(429)->assertJsonPath('retry_after', 200);
    });

    it('never shows a wait longer than the code\'s lifetime', function () use ($sendState) {
        $this->freezeSecond();
        config(['mfa.factors.sms.ttl' => 300, 'mfa.factors.sms.resend_cooldown' => 900]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertJsonPath('retry_after', 300);
        $this->travel(100)->seconds();

        expect($sendState($this))->toBe([true, 200]);
    });

    it('gives TOTP factors no send state', function () {
        Mfa::fakeSms();
        [$user, $totp] = $this->userWithFactor(FactorType::Totp);
        $sms = $this->createMfaFactor($user, FactorType::Sms);
        $this->loginWithSession($user);
        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $sms->id])->assertOk();

        $factors = collect($this->getJson(route('mfa.challenge'))->assertOk()->json('factors'))->keyBy('id');

        expect($factors[$totp->id])->toMatchArray(['code_sent' => false, 'retry_after' => null])
            ->and($factors[$sms->id])->toMatchArray(['code_sent' => true, 'retry_after' => 120]);
    });

    it('only reads: opening the page creates no code and spends no send budget', function () {
        config(['mfa.rate_limit.send_per_hour' => 1]);
        $sms = Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        foreach (range(1, 3) as $_) {
            $this->getJson(route('mfa.challenge'))->assertOk()->assertJsonPath('factors.0.code_sent', false);
        }

        expect($factor->otpCodes()->count())->toBe(0);
        $sms->assertNothingSent();
        // The one send this hour is still available.
        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
    });
});
