<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\FactorEnabled;
use StrontiumCorp\LaravelMfa\Events\RecoveryCodesGenerated;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\EnforceForEveryone;

/*
 * A first method only goes live once its recovery codes were downloaded and
 * the user pressed Finish: a user who verified the code and walked away (or
 * later deleted the authenticator entry) must not be locked out with no codes.
 */

beforeEach(function () {
    $this->user = $this->makeUser();
    $this->loginWithSession($this->user);
});

/** Start an authenticator setup and verify its code: the codes are staged. */
function verifyFirstFactor($test): array
{
    $test->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();
    $factor = MfaFactor::sole();
    $response = $test->postJson(route('mfa.factors.confirm', $factor->id), ['code' => $test->currentTotpCode($factor)])->assertOk();

    return [$factor, $response->json('recovery_codes'), $response];
}

it('keeps a verified first method off until its codes are downloaded and finished', function () {
    Event::fake([FactorEnabled::class, RecoveryCodesGenerated::class]);

    [$factor, $codes, $response] = verifyFirstFactor($this);

    $response->assertJsonPath('status', 'recovery-codes-pending')
        ->assertJsonPath('download_url', route('mfa.recovery-codes.download'))
        ->assertJsonPath('finish_url', route('mfa.recovery-codes.finish'));
    expect($codes)->toHaveCount(10)
        ->and($factor->fresh()->confirmed_at)->toBeNull()
        ->and(Mfa::hasConfirmedFactors($this->user))->toBeFalse()
        ->and($this->user->mfaRecoveryCodes()->count())->toBe(0);
    Event::assertNotDispatched(FactorEnabled::class);
    Event::assertNotDispatched(RecoveryCodesGenerated::class);
});

it('does not challenge a user who verified the code but never finished', function () {
    verifyFirstFactor($this);

    $this->flushSession();
    $this->freshGuards()->loginWithSession($this->user)->get('/dashboard')->assertOk();
});

it('refuses to finish before the codes are downloaded', function () {
    [$factor] = verifyFirstFactor($this);

    $this->postJson(route('mfa.recovery-codes.finish'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.recovery_codes.0', 'Download your recovery codes first.');

    expect($factor->fresh()->confirmed_at)->toBeNull();
});

it('serves the codes as a file and then finishes the setup', function () {
    Event::fake([FactorEnabled::class, RecoveryCodesGenerated::class]);
    [$factor, $codes] = verifyFirstFactor($this);

    $download = $this->get(route('mfa.recovery-codes.download'))->assertOk();

    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment; filename=')->toContain('-recovery-codes-')
        ->and($download->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store');
    foreach ($codes as $code) {
        expect($download->getContent())->toContain($code);
    }

    $this->postJson(route('mfa.recovery-codes.finish'))->assertOk()->assertJsonPath('status', 'factor-enabled');

    expect($factor->fresh()->isConfirmed())->toBeTrue()
        ->and(Mfa::hasConfirmedFactors($this->user))->toBeTrue()
        ->and(Mfa::isVerified(session()->driver(), $this->user))->toBeTrue()
        ->and(app(RecoveryCodes::class)->consume($this->user, $codes[0]))->toBeTrue();
    Event::assertDispatched(FactorEnabled::class);
    Event::assertDispatched(RecoveryCodesGenerated::class, fn ($e) => ($e->context['initial'] ?? false) === true);

    // Done: nothing left to download or finish.
    $this->get(route('mfa.recovery-codes.download'))->assertNotFound();
    $this->postJson(route('mfa.recovery-codes.finish'))->assertUnprocessable();
});

it('shows the same codes again after a reload', function () {
    [$factor, $codes] = verifyFirstFactor($this);

    $this->getJson(route('mfa.settings'))
        ->assertOk()
        ->assertJsonPath('pendingRecoveryCodes.codes', $codes)
        ->assertJsonPath('pendingRecoveryCodes.downloaded', false)
        ->assertJsonPath('pendingRecoveryCodes.factor.id', $factor->id)
        ->assertJsonPath('pendingRecoveryCodes.factor.type', 'totp')
        // The verified method waits on its codes, not on its setup.
        ->assertJsonCount(0, 'pending');

    $this->get(route('mfa.recovery-codes.download'))->assertOk();
    $this->getJson(route('mfa.settings'))->assertJsonPath('pendingRecoveryCodes.downloaded', true);
});

it('lets the codes expire with the setup, and prunes the abandoned method', function () {
    [$factor] = verifyFirstFactor($this);

    $this->travel(31)->minutes();

    $this->get(route('mfa.recovery-codes.download'))->assertNotFound();
    $this->postJson(route('mfa.recovery-codes.finish'))->assertUnprocessable()->assertJsonValidationErrors('recovery_codes');
    $this->getJson(route('mfa.settings'))->assertJsonPath('pendingRecoveryCodes', null);
    expect($factor->fresh()->confirmed_at)->toBeNull();

    $this->travel(1)->day();
    $this->artisan('model:prune', ['--model' => [MfaFactor::class]]);
    expect(MfaFactor::query()->exists())->toBeFalse();
});

it('prunes no confirmed method and no setup in progress', function () {
    $confirmed = $this->createMfaFactor($this->user);
    $abandoned = $this->user->mfaFactors()->create(['type' => FactorType::Email, 'label' => 'Email', 'destination' => 'a@example.com']);
    $this->travel(2)->days();
    $started = $this->user->mfaFactors()->create(['type' => FactorType::Email, 'label' => 'Email', 'destination' => 'b@example.com']);

    $this->artisan('model:prune', ['--model' => [MfaFactor::class]])->assertSuccessful();

    expect(MfaFactor::query()->pluck('id')->all())->toEqualCanonicalizing([$confirmed->id, $started->id])
        ->and($abandoned->fresh())->toBeNull();
});

it('holds an enforced user on the settings page until they finish', function () {
    config(['mfa.enforcement.policy' => EnforceForEveryone::class, 'mfa.enrollment_verification.required_for' => null]);

    verifyFirstFactor($this);
    $this->get('/dashboard')->assertRedirect(route('mfa.settings'));

    $this->get(route('mfa.recovery-codes.download'))->assertOk();
    $this->postJson(route('mfa.recovery-codes.finish'))->assertOk();
    $this->get('/dashboard')->assertOk();
});

it('enables a later method at once while unused codes remain', function () {
    $this->createMfaFactor($this->user);
    app(RecoveryCodes::class)->generate($this->user);
    $this->actingAsMfaVerified($this->user);
    $sms = Mfa::fakeSms();
    $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+15555550199'])->assertOk();
    $phone = MfaFactor::query()->whereNull('confirmed_at')->sole();

    $this->postJson(route('mfa.factors.confirm', $phone->id), ['code' => $sms->lastCodeFor('+15555550199')])
        ->assertOk()
        ->assertJsonPath('status', 'factor-enabled')
        ->assertJsonMissingPath('recovery_codes');

    expect($phone->fresh()->isConfirmed())->toBeTrue();
});

describe('new codes for an account that has some', function () {
    beforeEach(function () {
        $this->createMfaFactor($this->user);
        $this->old = app(RecoveryCodes::class)->generate($this->user);
        $this->actingAsMfaVerified($this->user);
    });

    it('keeps the old codes working until the new ones are downloaded and finished', function () {
        Event::fake([RecoveryCodesGenerated::class]);

        $new = $this->postJson(route('mfa.recovery-codes.store'))
            ->assertOk()
            ->assertJsonPath('status', 'recovery-codes-pending')
            ->json('recovery_codes');

        expect($new)->toHaveCount(10)->not->toContain($this->old[0])
            ->and(app(RecoveryCodes::class)->remaining($this->user))->toBe(10);
        $this->getJson(route('mfa.settings'))->assertJsonPath('pendingRecoveryCodes.factor', null);
        $this->postJson(route('mfa.recovery-codes.finish'))->assertUnprocessable();
        Event::assertNotDispatched(RecoveryCodesGenerated::class);

        $this->get(route('mfa.recovery-codes.download'))->assertOk();
        $this->postJson(route('mfa.recovery-codes.finish'))->assertOk()->assertJsonPath('status', 'recovery-codes-generated');

        expect(app(RecoveryCodes::class)->consume($this->user, $this->old[1]))->toBeFalse()
            ->and(app(RecoveryCodes::class)->consume($this->user, $new[0]))->toBeTrue();
        Event::assertDispatched(RecoveryCodesGenerated::class);
    });

    it('keeps the old codes when the new set is discarded', function () {
        $this->postJson(route('mfa.recovery-codes.store'))->assertOk();

        $this->deleteJson(route('mfa.recovery-codes.discard'))->assertOk()->assertJsonPath('status', 'recovery-codes-discarded');

        $this->get(route('mfa.recovery-codes.download'))->assertNotFound();
        expect(app(RecoveryCodes::class)->consume($this->user, $this->old[0]))->toBeTrue();
    });
});

describe('the downloaded file', function () {
    beforeEach(function () {
        config(['mfa.factors.totp.issuer' => 'Artistly', 'app.env' => 'staging']);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 02:05:00', 'UTC'));
    });

    it('is named after the app, the account and the day, in the browser\'s time zone', function () {
        $this->user->forceFill(['email' => 'mojahid@mail.com'])->save();
        [, $codes] = verifyFirstFactor($this);

        $file = $this->get(route('mfa.recovery-codes.download', ['tz' => 'Asia/Dhaka']))->assertOk();

        expect($file->headers->get('Content-Disposition'))->toBe('attachment; filename="artistly-staging-recovery-codes-mojahid@mail.com-2026-10-09.txt"')
            ->and($file->getContent())->toBe(
                "Artistly (staging) two-factor recovery codes\n".
                "Account: mojahid@mail.com\n".
                "Downloaded: 2026-10-09 08:05 (Asia/Dhaka)\n\n".
                "Each line below is one code. Each code works once. Getting new codes cancels these.\n\n".
                implode("\n", $codes)."\n",
            );
    });

    it('uses the app\'s time zone for an unknown one, and keeps file names safe', function () {
        config(['app.timezone' => 'UTC']);
        $this->user->forceFill(['email' => 'a/b\\c:d*e?f"g<h>i|j@x.com'])->save();
        verifyFirstFactor($this);

        $file = $this->get(route('mfa.recovery-codes.download', ['tz' => 'Mars/Olympus']))->assertOk();

        expect($file->headers->get('Content-Disposition'))->toBe('attachment; filename="artistly-staging-recovery-codes-a-b-c-d-e-f-g-h-i-j@x.com-2026-10-09.txt"')
            ->and($file->getContent())->toContain("Downloaded: 2026-10-09 02:05 (UTC)\n");
    });
});
