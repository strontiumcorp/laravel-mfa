<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\SortedMiddleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ChallengeRequired;
use StrontiumCorp\LaravelMfa\Events\EnrollmentRequired;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Http\Middleware\EnsureMfaVerified;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\EnforceForEveryone;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\Role;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\User;

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

it('challenges before route model binding, so an unverified user learns nothing about records', function () {
    // /profiles/{profile} binds a model; a missing id would be a 404.
    Route::middleware(['web', 'auth'])->get('/profiles/{profile}', fn (User $profile) => $profile->id);
    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);

    $this->get("/profiles/{$user->id}")->assertRedirect(route('mfa.challenge'));
    $this->get('/profiles/999999')->assertRedirect(route('mfa.challenge'));

    $this->actingAsMfaVerified($user);
    $this->get("/profiles/{$user->id}")->assertOk();
    $this->get('/profiles/999999')->assertNotFound();
});

it('runs after the session and auth middleware, and before route model binding', function () {
    Route::middleware(['web', 'auth'])->get('/profiles/{profile}', fn (User $profile) => $profile->id)->name('profiles.show');
    Route::getRoutes()->refreshNameLookups();
    app(Kernel::class); // syncs its groups and priority onto the router
    $order = array_values(array_map(
        fn ($m) => is_string($m) ? explode(':', $m)[0] : $m,
        // Sorted by the router's priority list, as it runs them.
        (new SortedMiddleware(app('router')->middlewarePriority, app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('profiles.show'))))->all(),
    ));
    $at = fn (string $class) => array_search($class, $order, true);

    expect($at(EnsureMfaVerified::class))->toBeGreaterThan($at(StartSession::class))
        ->toBeGreaterThan($at(Authenticate::class))
        ->toBeLessThan($at(SubstituteBindings::class));
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

    it('reads the "has MFA" cache once per request, however often it is asked', function () {
        // The gate, the shared context (as HandleInertiaRequests would) and
        // the nudge all ask; the answer is kept on the request.
        Route::middleware(['web', 'auth'])->get('/shared', fn () => Mfa::context()->toArray());
        $reads = [];
        Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
            if (str_contains($e->key, 'factor-types')) {
                $reads[] = $e->key;
            }
        });
        $user = $this->makeUser();
        $this->loginWithSession($user)->get('/shared')->assertOk(); // fills the cache
        $reads = [];
        $this->get('/shared')->assertOk()->assertJsonPath('nudge.show', true);
        expect($reads)->toHaveCount(1);

        // A factor added mid-request is seen at once (the write-through updates it).
        $reads = [];
        Route::middleware(['web', 'auth'])->get('/add', function () use ($user) {
            $before = Mfa::hasConfirmedFactors($user);
            test()->createMfaFactor($user);

            return [$before, Mfa::hasConfirmedFactors($user)];
        });
        $this->get('/add')->assertOk()->assertExactJson([false, true]);
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

describe('denials of non-GET and background requests', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth'])->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/things/{id}', fn () => 'thing');
    });

    it('answers a blocked PUT, PATCH, DELETE or POST with 303, so the browser follows it with a GET', function (string $method) {
        [$user] = $this->userWithFactor();
        $this->loginWithSession($user);

        // A stale tab's router.put(). The gate runs before HandleInertiaRequests,
        // and inertia-laravel 2.x (artistly) has no global 302 → 303 middleware.
        $this->call($method, '/things/1', [], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])
            ->assertStatus(303)
            ->assertRedirect(route('mfa.challenge'));
        $this->call($method, '/things/1')->assertStatus(303);
    })->with(['PUT', 'PATCH', 'DELETE', 'POST']);

    it('keeps 302 for GET and HEAD', function (string $method) {
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)->call($method, '/things/1')->assertStatus(302)->assertRedirect(route('mfa.challenge'));
    })->with(['GET', 'HEAD']);

    it('answers 303 when an enforced user who must enroll sends a non-GET request', function () {
        config(['mfa.enforcement.policy' => EnforceForEveryone::class]);

        $this->loginWithSession($this->makeUser())
            ->delete('/things/1', [], ['X-Inertia' => 'true'])
            ->assertStatus(303)
            ->assertRedirect(route('mfa.settings'));
    });

    it("answers a script's fetch() (Fetch Metadata, not a navigation) with the JSON 403 and keeps the intended URL", function (string $mode) {
        [$user] = $this->userWithFactor();
        $this->loginWithSession($user)->get('/dashboard?tab=2');

        // A background poll in a stale tab: fetch() sends Accept: */* and no X-Requested-With.
        $this->get('/things/1?poll=1', ['Accept' => '*/*', 'Sec-Fetch-Mode' => $mode, 'Sec-Fetch-Dest' => 'empty'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Multi-factor authentication required.', 'error' => 'mfa_required', 'redirect' => route('mfa.challenge')]);

        expect(session('url.intended'))->toEndWith('/dashboard?tab=2');
    })->with(['cors', 'same-origin', 'no-cors']);

    it('gives enforced users the enrollment JSON 403 for a fetch()', function () {
        config(['mfa.enforcement.policy' => EnforceForEveryone::class]);

        $this->loginWithSession($this->makeUser())
            ->get('/things/1', ['Accept' => '*/*', 'Sec-Fetch-Mode' => 'cors'])
            ->assertForbidden()
            ->assertJson(['error' => 'mfa_enrollment_required', 'redirect' => route('mfa.settings')]);
    });

    it('remembers the intended URL for a top-level navigation', function () {
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)
            ->get('/things/1?tab=2', ['Accept' => 'text/html,*/*;q=0.8', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])
            ->assertRedirect(route('mfa.challenge'));

        expect(session('url.intended'))->toEndWith('/things/1?tab=2');
    });

    it('redirects, without remembering it, a navigation that is not the page itself', function (array $headers) {
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)
            ->get('/things/1', ['Accept' => 'text/html,*/*;q=0.8', 'Sec-Fetch-Mode' => 'navigate', ...$headers])
            ->assertRedirect(route('mfa.challenge'));

        expect(session('url.intended'))->toBeNull();
    })->with([
        'an iframe' => [['Sec-Fetch-Dest' => 'iframe']],
        'a prefetch' => [['Sec-Fetch-Dest' => 'document', 'Sec-Purpose' => 'prefetch']],
        'a prerender' => [['Sec-Fetch-Dest' => 'document', 'Sec-Purpose' => 'prefetch;prerender']],
        'a legacy prefetch' => [['Sec-Fetch-Dest' => 'document', 'Purpose' => 'prefetch']],
    ]);

    it('without Fetch Metadata, remembers the URL only for requests that ask for HTML', function () {
        [$user] = $this->userWithFactor();
        $this->loginWithSession($user);

        // An old browser's fetch(): Accept: */*. Redirected as before, but not remembered.
        $this->get('/things/1?poll=1', ['Accept' => '*/*'])->assertRedirect(route('mfa.challenge'));
        expect(session('url.intended'))->toBeNull();

        $this->get('/things/1?page=1', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])->assertRedirect(route('mfa.challenge'));
        expect(session('url.intended'))->toEndWith('/things/1?page=1');
    });

    it('still redirects Inertia visits, which send Fetch Metadata too', function () {
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)
            ->get('/things/1', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml', 'Sec-Fetch-Mode' => 'cors'])
            ->assertStatus(302)
            ->assertRedirect(route('mfa.challenge'));

        expect(session('url.intended'))->toBeNull();
    });
});
