<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ChallengeRequired;
use StrontiumCorp\LaravelMfa\Events\EnrollmentRequired;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\EnforceForEveryone;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\Role;

it('lets guests through', function () {
    $this->get('/public')->assertOk();
});

it('lets users without MFA through', function () {
    $this->loginWithSession($this->makeUser())->get('/dashboard')->assertOk();
});

it('challenges users with MFA and remembers where they were going', function () {
    Event::fake([ChallengeRequired::class]);
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->get('/dashboard?tab=2')->assertRedirect(route('mfa.challenge'));

    expect(session('url.intended'))->toEndWith('/dashboard?tab=2');
    Event::assertDispatched(ChallengeRequired::class, fn ($e) => $e->user->is($user) && $e->flowId !== null);
});

it('returns a JSON 403 for API-style requests', function () {
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->getJson('/api/me')
        ->assertForbidden()
        ->assertJson(['error' => 'mfa_required', 'redirect' => route('mfa.challenge')]);
});

it('redirects Inertia requests instead of returning JSON', function () {
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)
        ->get('/dashboard', ['X-Inertia' => 'true', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertRedirect(route('mfa.challenge'));
});

it('lets verified sessions through', function () {
    [$user] = $this->userWithFactor();

    $this->actingAsMfaVerified($user)->get('/dashboard')->assertOk();
});

it('ignores actingAs(), which never writes the session (host test suites keep passing)', function () {
    [$user] = $this->userWithFactor();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('never blocks webhooks that Auth::setUser() mid-request', function () {
    $this->userWithFactor();

    $this->post('/hooks/test')->assertOk()->assertSee('hook:1');
});

it('always allows the challenge routes and configured exceptions', function () {
    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);

    $this->getJson(route('mfa.challenge'))->assertOk();
    $this->post('/logout')->assertOk();
});

it('honours the kill switch', function () {
    config(['mfa.enabled' => false]);
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->get('/dashboard')->assertOk();
});

it('challenges logins restored from a remember-me cookie on the first request', function () {
    [$user] = $this->userWithFactor();
    $user->forceFill(['remember_token' => 'remember-me-token'])->save();

    $guard = auth()->guard('web');
    $cookie = $user->id.'|remember-me-token|'.$user->getAuthPassword();

    $this->withCookie($guard->getRecallerName(), $cookie)
        ->get('/dashboard')
        ->assertRedirect(route('mfa.challenge'));
});

it('blocks MFA settings for users who have factors but have not verified', function () {
    [$user] = $this->userWithFactor();

    // Otherwise a stolen password could be used to add an attacker's factor.
    $this->loginWithSession($user)->getJson(route('mfa.settings'))->assertForbidden();
});

it('allows MFA settings for users without factors', function () {
    $this->loginWithSession($this->makeUser())->getJson(route('mfa.settings'))->assertOk();
});

it('sends enforced users without factors to enroll', function () {
    Event::fake([EnrollmentRequired::class]);
    config(['mfa.enforcement.policy' => EnforceForAdmins::class]);

    $admin = $this->makeUser(['is_admin' => true]);
    $this->loginWithSession($admin);

    $this->get('/dashboard')->assertRedirect(route('mfa.settings'));
    $this->getJson(route('mfa.settings'))->assertOk()->assertJson(['mustEnroll' => true]);
    Event::assertDispatched(EnrollmentRequired::class);

    $this->freshGuards()->loginWithSession($this->makeUser())->get('/dashboard')->assertOk();
});

describe('performance', function () {
    function mfaQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'mfa_')) {
                $queries[] = $q->sql;
            }
        });
        $callback();

        return $queries;
    }

    it('runs zero MFA queries for verified sessions', function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);

        expect(mfaQueries(fn () => $this->get('/dashboard')->assertOk()))->toBeEmpty();
    });

    it('caches "has no MFA" so repeat requests run zero MFA queries', function () {
        $this->loginWithSession($this->makeUser());
        $this->get('/dashboard');

        expect(mfaQueries(fn () => $this->get('/dashboard')->assertOk()))->toBeEmpty();
    });

    it('busts the cache when a factor is added', function () {
        $user = $this->makeUser();
        $this->loginWithSession($user)->get('/dashboard')->assertOk();

        $this->createMfaFactor($user, FactorType::Totp);

        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });
});

describe('enforcement (D5)', function () {
    it('enforces for a list of roles, read from a string or enum "role" attribute', function () {
        config(['mfa.enforcement.roles' => ['admin', 'super_admin']]);

        expect(Mfa::mustEnroll($this->makeUser()->forceFill(['role' => 'admin'])))->toBeTrue()
            ->and(Mfa::mustEnroll($this->makeUser()->forceFill(['role' => Role::Admin])))->toBeTrue()
            ->and(Mfa::mustEnroll($this->makeUser()->forceFill(['role' => Role::Member])))->toBeFalse()
            ->and(Mfa::mustEnroll($this->makeUser()))->toBeFalse();
    });

    it('enforces when either the roles or the policy class says so', function () {
        config(['mfa.enforcement.roles' => ['admin'], 'mfa.enforcement.policy' => EnforceForAdmins::class]);

        expect(Mfa::mustEnroll($this->makeUser(['is_admin' => true])))->toBeTrue()
            ->and(Mfa::mustEnroll($this->makeUser()->forceFill(['role' => 'admin'])))->toBeTrue()
            ->and(Mfa::mustEnroll($this->makeUser()))->toBeFalse();
    });

    it('never requires enrollment from users who already have a factor', function () {
        config(['mfa.enforcement.policy' => EnforceForEveryone::class, 'mfa.enforcement.required_types' => []]);

        [$user] = $this->userWithFactor();
        expect(Mfa::mustEnroll($user))->toBeFalse();
    });
});
