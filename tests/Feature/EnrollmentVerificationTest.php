<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\ChallengeDeliveryFailed;
use StrontiumCorp\LaravelMfa\Events\EnrollmentLinkIssued;
use StrontiumCorp\LaravelMfa\Events\EnrollmentVerificationRequired;
use StrontiumCorp\LaravelMfa\Events\EnrollmentVerificationSent;
use StrontiumCorp\LaravelMfa\Events\EnrollmentVerified;
use StrontiumCorp\LaravelMfa\Events\VerificationFailed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Notifications\EnrollmentCodeNotification;
use StrontiumCorp\LaravelMfa\Support\EnrollmentVerification;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\CustomEnrollmentCode;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\EnforceForEveryone;

beforeEach(function () {
    Notification::fake();
    Mfa::fakeCodes('123456');
    config(['mfa.enforcement.policy' => EnforceForEveryone::class]);
});

/** The code the last enrollment verification email carried. */
function sentEnrollmentCode(): ?string
{
    $code = null;
    Notification::assertSentOnDemand(EnrollmentCodeNotification::class, function (EnrollmentCodeNotification $n) use (&$code) {
        $code = $n->code;

        return true;
    });

    return $code;
}

describe('a stolen password alone', function () {
    it("can't add the first factor of an account that must enroll", function () {
        $owner = $this->makeUser();
        $this->loginWithSession($owner); // all the attacker has: the password

        $this->get('/dashboard')->assertRedirect(route('mfa.settings'));
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertStatus(423)->assertJson(['error' => 'enrollment_verification_required']);
        $this->postJson('/mfa/factors', ['type' => 'email', 'destination' => 'attacker@example.com'])->assertStatus(423);

        expect($owner->mfaFactors()->count())->toBe(0);
    });

    it("can't confirm a factor that is somehow pending either", function () {
        $owner = $this->makeUser();
        $this->loginWithSession($owner)->withEnrollmentVerified($owner);
        $secret = $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk()->json('setup.secret');
        $id = $owner->mfaFactors()->sole()->id;

        // The proof is gone (e.g. this session was reused after a logout of another tab).
        session()->forget(EnrollmentVerification::SESSION_PREFIX);

        $this->postJson("/mfa/factors/{$id}/confirm", ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30))])
            ->assertStatus(423);
        expect($owner->mfaFactors()->whereNotNull('confirmed_at')->exists())->toBeFalse();
    });

    it('can add it once the session proves access to the account email', function () {
        Event::fake([EnrollmentVerificationRequired::class, EnrollmentVerificationSent::class, EnrollmentVerified::class]);
        $owner = $this->makeUser(['email' => 'owner@example.com']);
        $this->loginWithSession($owner);

        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertStatus(423);
        $this->postJson('/mfa/enrollment-verification/send')->assertOk();

        Notification::assertSentOnDemand(EnrollmentCodeNotification::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes === ['mail' => 'owner@example.com'] && $n->ttlSeconds === 600);
        $this->postJson('/mfa/enrollment-verification', ['code' => sentEnrollmentCode()])->assertOk();

        $secret = $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk()->json('setup.secret');
        $id = $owner->mfaFactors()->sole()->id;
        $this->postJson("/mfa/factors/{$id}/confirm", ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30))])->assertOk();
        $this->get('/dashboard')->assertOk();

        Event::assertDispatched(EnrollmentVerificationRequired::class, fn ($e) => $e->user->is($owner) && $e->context === ['path' => '/mfa/factors']);
        Event::assertDispatched(EnrollmentVerificationSent::class, fn ($e) => $e->factorType === FactorType::Email && $e->context === ['queued' => false]);
        Event::assertDispatched(EnrollmentVerified::class, fn ($e) => $e->user->is($owner) && $e->context === ['method' => 'email']);
    });
});

describe('who must verify', function () {
    it('is not asked of users enforcement does not apply to, by default', function () {
        config(['mfa.enforcement.policy' => null]);
        $this->loginWithSession($this->makeUser());

        $this->getJson('/mfa/settings')->assertJsonPath('enrollmentVerification', null);
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
    });

    it('is asked of everyone adding a first factor with required_for "everyone"', function () {
        config(['mfa.enforcement.policy' => null, 'mfa.enrollment_verification.required_for' => 'everyone']);
        $this->loginWithSession($this->makeUser());

        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertStatus(423);
    });

    it('is never asked when turned off', function (mixed $off) {
        config(['mfa.enrollment_verification.required_for' => $off]);
        $this->loginWithSession($this->makeUser());

        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
    })->with([null, false, 'off', '']);

    it('is not asked of a verified user adding another factor', function () {
        [$user] = $this->userWithFactor(FactorType::Email);
        $this->actingAsMfaVerified($user);

        $this->getJson('/mfa/settings')->assertJsonPath('enrollmentVerification', null);
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
    });

    it('is forgotten on logout', function () {
        $user = $this->makeUser();
        $this->loginWithSession($user)->withEnrollmentVerified($user);
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();

        $this->post('/logout');
        $this->freshGuards()->loginWithSession($user);

        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertStatus(423);
    });

    it('asks Inertia pages with a validation error, like the password prompt', function () {
        config(['mfa.ui.driver' => 'inertia']);
        $this->loginWithSession($this->makeUser());

        $this->from(route('mfa.settings'))->post('/mfa/factors', ['type' => 'totp'], ['X-Inertia' => 'true'])
            ->assertRedirect(route('mfa.settings'))
            ->assertSessionHasErrors(['enrollment_verification_required' => 'Enter the code we email you to confirm it is you.']);
    });
});

describe('the email code', function () {
    it('only works in the session that asked for it', function () {
        $user = $this->makeUser();
        $this->loginWithSession($user)->postJson('/mfa/enrollment-verification/send')->assertOk();
        $code = sentEnrollmentCode();

        $this->flushSession();
        $this->freshGuards()->loginWithSession($user);

        $this->postJson('/mfa/enrollment-verification', ['code' => $code])->assertStatus(422)
            ->assertJsonPath('errors.code.0', FailureReason::NoActiveCode->message());
    });

    it('expires after factors.email.ttl', function () {
        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk();
        $this->travel(601)->seconds();

        $this->postJson('/mfa/enrollment-verification', ['code' => '123456'])->assertStatus(422)
            ->assertJsonPath('errors.code.0', FailureReason::Expired->message());
    });

    it('is burned after factors.email.max_attempts wrong guesses', function () {
        Event::fake([VerificationFailed::class]);
        config(['mfa.factors.email.max_attempts' => 3]);
        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk();

        $this->postJson('/mfa/enrollment-verification', ['code' => '000000'])->assertStatus(422)->assertJsonPath('errors.code.0', FailureReason::InvalidCode->message());
        $this->postJson('/mfa/enrollment-verification', ['code' => '000001'])->assertStatus(422);
        $this->postJson('/mfa/enrollment-verification', ['code' => '000002'])->assertStatus(422)->assertJsonPath('errors.code.0', FailureReason::TooManyAttempts->message());
        $this->postJson('/mfa/enrollment-verification', ['code' => '123456'])->assertStatus(422)->assertJsonPath('errors.code.0', FailureReason::NoActiveCode->message());

        Event::assertDispatched(VerificationFailed::class, fn ($e) => $e->reason === FailureReason::InvalidCode && $e->context === ['stage' => 'enrollment_verification', 'attempts_remaining' => 2]);
    });

    it('counts toward the verify rate limit', function () {
        config(['mfa.rate_limit.verify_per_minute' => 2]);
        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk();

        $this->postJson('/mfa/enrollment-verification', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/mfa/enrollment-verification', ['code' => '000001'])->assertStatus(422);
        $this->postJson('/mfa/enrollment-verification', ['code' => '123456'])->assertStatus(429);
    });

    it('waits out the email resend cooldown between sends', function () {
        $this->freezeSecond();
        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk()->assertJson(['retry_after' => 120]);

        $this->postJson('/mfa/enrollment-verification/send')->assertStatus(429)->assertJson(['retry_after' => 120]);
        $this->travel(120)->seconds();
        $this->postJson('/mfa/enrollment-verification/send')->assertOk()->assertJson(['retry_after' => 240]);
    });

    it("keeps its wait across sessions, so a new login doesn't skip it", function () {
        $user = $this->makeUser();
        $this->loginWithSession($user)->postJson('/mfa/enrollment-verification/send')->assertOk();

        $this->flushSession();
        $this->freshGuards()->loginWithSession($user)->postJson('/mfa/enrollment-verification/send')->assertStatus(429);
    });

    it("counts toward the account's send caps", function () {
        config(['mfa.rate_limit.send_per_hour' => 1]);
        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk();
        $this->travel(121)->seconds();

        $this->postJson('/mfa/enrollment-verification/send')->assertStatus(429)
            ->assertJsonPath('errors.code.0', FailureReason::RateLimited->message());
    });

    it('counts toward the email daily cap from this network', function () {
        config(['mfa.factors.email.send_per_day' => 1]);
        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk();
        $this->travel(601)->seconds();

        $this->postJson('/mfa/enrollment-verification/send')->assertStatus(429)->assertJsonStructure(['retry_after']);
    });

    it('goes out on the delivery queue, encrypted, when one is set', function () {
        config(['mfa.delivery.queue' => 'mfa', 'queue.default' => 'database']);
        Event::fake([EnrollmentVerificationSent::class]);

        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk();

        Notification::assertSentOnDemand(EnrollmentCodeNotification::class, fn (EnrollmentCodeNotification $n) => $n->queue === 'mfa' && $n instanceof ShouldBeEncrypted);
        Event::assertDispatched(EnrollmentVerificationSent::class, fn ($e) => $e->context === ['queued' => true]);
    });

    it('uses the configured notification class', function () {
        config(['mfa.enrollment_verification.notification' => CustomEnrollmentCode::class]);

        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk();

        Notification::assertSentOnDemand(CustomEnrollmentCode::class);
    });

    it('says to ask for a link when email codes are off or the account has no email', function (array $config, array $attributes) {
        config($config);
        $this->loginWithSession($this->makeUser($attributes));

        $this->getJson('/mfa/settings')->assertJsonPath('enrollmentVerification', ['email' => null]);
        $this->postJson('/mfa/enrollment-verification/send')->assertStatus(422)
            ->assertJsonPath('errors.code.0', FailureReason::EnrollmentLinkRequired->message());
        Notification::assertNothingSent();
    })->with([
        'email codes off' => [['mfa.enrollment_verification.email' => false], []],
        'no email address' => [[], ['email' => '']],
    ]);

    it('drops a code whose delivery failed, so the next send needs no wait', function () {
        Event::fake([ChallengeDeliveryFailed::class]);
        Notification::swap(new class extends NotificationFake
        {
            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new RuntimeException('SMTP 550 for <owner@example.com>');
            }
        });
        $this->withoutExceptionHandling(); // report() must not rethrow

        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertStatus(422)
            ->assertJsonPath('errors.code.0', FailureReason::DeliveryFailed->message());

        Event::assertDispatched(ChallengeDeliveryFailed::class, fn ($e) => $e->context === ['stage' => 'enrollment_verification', 'error' => '[email] RuntimeException']);
        expect(session(EnrollmentVerification::CODE_KEY))->toBeNull();

        Notification::fake();
        $this->postJson('/mfa/enrollment-verification/send')->assertOk();
    });

    it('answers "verified" without sending when no proof is needed', function () {
        config(['mfa.enforcement.policy' => null]);

        $this->loginWithSession($this->makeUser())->postJson('/mfa/enrollment-verification/send')->assertOk()->assertExactJson(['status' => 'enrollment-verified']);
        $this->postJson('/mfa/enrollment-verification', ['code' => '000000'])->assertOk()->assertExactJson(['status' => 'enrollment-verified']);
        Notification::assertNothingSent();
    });
});

describe("an administrator's link", function () {
    it('proves ownership once, in the user\'s own session', function () {
        Event::fake([EnrollmentLinkIssued::class, EnrollmentVerified::class]);
        config(['mfa.enrollment_verification.email' => false]);
        $user = $this->makeUser();
        $url = Mfa::enrollmentLink($user);

        $this->loginWithSession($user)->get($url)->assertRedirect(route('mfa.settings'));
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();

        Event::assertDispatched(EnrollmentLinkIssued::class, fn ($e) => $e->user->is($user) && $e->context['via'] === 'app');
        Event::assertDispatched(EnrollmentVerified::class, fn ($e) => $e->context === ['method' => 'link'] && $e->factorType === null);

        // One use: a second session can't use it again.
        $this->post('/logout');
        $this->freshGuards()->loginWithSession($user)->get($url)->assertForbidden();
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertStatus(423);
    });

    it("is refused for another account's session", function () {
        Event::fake([VerificationFailed::class]);
        $url = Mfa::enrollmentLink($this->makeUser());

        $this->loginWithSession($this->makeUser())->get($url)->assertForbidden();

        Event::assertDispatched(VerificationFailed::class, fn ($e) => $e->reason === FailureReason::InvalidLink && $e->context['stage'] === 'enrollment_link');
    });

    it('is revoked by a password change', function () {
        $user = $this->makeUser();
        $url = Mfa::enrollmentLink($user);
        $user->forceFill(['password' => 'changed'])->save();

        $this->loginWithSession($user)->get($url)->assertForbidden();
    });

    it('expires after link_ttl minutes', function () {
        config(['mfa.enrollment_verification.link_ttl' => 10]);
        $user = $this->makeUser();
        $url = Mfa::enrollmentLink($user);
        $this->travel(11)->minutes();

        $this->loginWithSession($user)->get($url)->assertForbidden();
    });

    it('is refused when tampered with', function () {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $url = str_replace('/'.$user->id.'?', '/'.$other->id.'?', Mfa::enrollmentLink($user));

        $this->loginWithSession($other)->get($url)->assertForbidden();
    });

    it('sends a signed-out user to log in first, then works', function () {
        $user = $this->makeUser();
        $url = Mfa::enrollmentLink($user);

        $this->get($url)->assertRedirect('/login');
        $this->loginWithSession($user)->get($url)->assertRedirect(route('mfa.settings'));
        expect(app(EnrollmentVerification::class)->isVerified(session()->driver(), $user))->toBeTrue();
    });

    it('is created by mfa:enrollment-link', function () {
        $user = $this->makeUser(['email' => 'jane@example.com']);

        $this->artisan('mfa:enrollment-link', ['user' => 'jane@example.com', '--minutes' => 30])
            ->expectsOutputToContain('valid for 30 minute(s)')
            ->expectsOutputToContain(URL::to('/mfa/enrollment-verification/'.$user->id))
            ->assertSuccessful();
    });

    it('mfa:enrollment-link warns when the link is not needed, and refuses bad minutes', function () {
        [$user] = $this->userWithFactor();
        $plain = $this->makeUser();
        config(['mfa.enforcement.policy' => null]);

        $this->artisan('mfa:enrollment-link', ['user' => $user->id])->expectsOutputToContain('already has a factor')->assertSuccessful();
        $this->artisan('mfa:enrollment-link', ['user' => $plain->id])->expectsOutputToContain("doesn't need verification")->assertSuccessful();
        $this->artisan('mfa:enrollment-link', ['user' => $plain->id, '--minutes' => '0'])->assertFailed();
        $this->artisan('mfa:enrollment-link', ['user' => 'nobody@example.com'])->assertFailed();
    });
});

describe('review fixes', function () {
    it('names what is wrong with a refused link in the failure event', function (Closure $break, string $problem, string $message) {
        Event::fake([VerificationFailed::class]);
        $user = $this->makeUser();
        $other = $this->makeUser();
        $url = Mfa::enrollmentLink($user);
        $session = $break($user, $other);

        $this->loginWithSession($session)->get($url)->assertForbidden()->assertSee($message);

        Event::assertDispatched(VerificationFailed::class, fn ($e) => $e->reason === FailureReason::InvalidLink && $e->context === ['stage' => 'enrollment_link', 'problem' => $problem]);
    })->with([
        'another account' => [fn ($user, $other) => $other, 'other_account', 'This setup link is for another account.'],
        'password changed' => [function ($user) {
            $user->forceFill(['password' => 'changed'])->save();

            return $user;
        }, 'revoked', 'This setup link is no longer valid. Ask for a new one.'],
    ]);

    it('records who issued a link: the app, or an administrator at the console', function () {
        Event::fake([EnrollmentLinkIssued::class]);
        $user = $this->makeUser();

        Mfa::enrollmentLink($user);
        $this->artisan('mfa:enrollment-link', ['user' => $user->id])->assertSuccessful();

        Event::assertDispatched(EnrollmentLinkIssued::class, fn ($e) => $e->context['via'] === 'app' && ! isset($e->context['by_administrator']));
        Event::assertDispatched(EnrollmentLinkIssued::class, fn ($e) => $e->context['via'] === 'console:mfa:enrollment-link' && $e->context['by_administrator'] === true);
    });
});
