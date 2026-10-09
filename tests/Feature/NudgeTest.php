<?php

use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Events\NudgeDismissed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\MfaServiceProvider;
use StrontiumCorp\LaravelMfa\Support\Nudge;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\EnforceForEveryone;

// The "turn on two-factor" nudge: who sees it (Mfa::context()->nudge), and
// "Not today" (POST mfa.nudge.dismiss), which hides it for that user until
// their next local midnight.

beforeEach(function () {
    // An app page: the context as HandleInertiaRequests would share it.
    Route::middleware(['web', 'auth'])->get('/app-page', fn () => Mfa::context()->nudge);
    // Friday 9 October 2026, 20:00 UTC: already Saturday 02:00 in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-10-09T20:00:00Z'));
});

$request = fn () => request()->setLaravelSession(session()->driver());
$show = fn () => Mfa::context(request()->setLaravelSession(session()->driver()))->nudge['show'];

it('shows the nudge to a logged-in user with no factor, with the configured copy', function () use ($request) {
    $this->loginWithSession($this->makeUser());

    expect(Mfa::context($request())->nudge)->toBe([
        'show' => true,
        'title' => 'Protect your account',
        'body' => 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
        'button' => 'Turn on',
        'dismissLabel' => 'Not today',
        'dismissUrl' => route('mfa.nudge.dismiss'),
    ]);

    $this->get('/app-page')->assertOk()->assertJsonPath('show', true);
});

it('translates the copy', function () use ($request) {
    app('translator')->addLines(['*.Protect your account' => 'Protégez votre compte'], 'fr');
    app()->setLocale('fr');
    $this->loginWithSession($this->makeUser());

    expect(Mfa::context($request())->nudge['title'])->toBe('Protégez votre compte');
});

it('does not show the nudge to guests, users with a factor, or enforced users', function () use ($show) {
    expect($show())->toBeFalse();

    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);
    expect($show())->toBeFalse();

    $this->actingAsMfaVerified($user);
    expect($show())->toBeFalse();

    config(['mfa.enforcement.policy' => EnforceForEveryone::class]);
    $this->freshGuards()->loginWithSession($this->makeUser());
    expect($show())->toBeFalse();
});

it('does not show the nudge when it, MFA or the MFA routes are off', function (array $config) use ($show) {
    $this->loginWithSession($this->makeUser());
    config($config);

    expect($show())->toBeFalse();
})->with([
    'nudge off' => [['mfa.nudge.enabled' => false]],
    'MFA off' => [['mfa.enabled' => false]],
    'routes off' => [['mfa.routes.enabled' => false]],
]);

it('does not show the nudge on the MFA pages themselves', function () {
    // As the settings page's shared props see it: any route named mfa.*.
    Route::middleware(['web', 'auth'])->get('/mfa/example', fn () => Mfa::context()->nudge)->name('mfa.example');
    $this->loginWithSession($this->makeUser());

    $this->get('/mfa/example')->assertOk()->assertJsonPath('show', false);
    $this->get('/app-page')->assertOk()->assertJsonPath('show', true);
});

it('hides the nudge until the next midnight in the browser timezone, for that user', function () use ($show) {
    Event::fake([NudgeDismissed::class]);
    $user = $this->makeUser();
    $this->loginWithSession($user);

    // 02:00 on Saturday in Dhaka (UTC+6): hidden until Sunday 00:00 there,
    // which is Saturday 18:00 UTC (not the app's own midnight, 04:00 later).
    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'Asia/Dhaka'])
        ->assertOk()
        ->assertExactJson(['status' => 'nudge-dismissed', 'until' => '2026-10-10T18:00:00+00:00']);

    expect(app(Nudge::class)->dismissedUntil($user)?->toIso8601String())->toBe('2026-10-10T18:00:00+00:00')
        ->and($show())->toBeFalse();
    $this->get('/app-page')->assertJsonPath('show', false);

    Event::assertDispatched(NudgeDismissed::class, fn ($e) => $e->user->is($user) && $e->context === ['until' => '2026-10-10T18:00:00+00:00']);

    $this->travelTo(CarbonImmutable::parse('2026-10-10T17:59:59Z'));
    expect($show())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-10-10T18:00:00Z'));
    expect($show())->toBeTrue();
});

it('gives the time in the app timezone', function () {
    config(['app.timezone' => 'America/New_York']);
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'Asia/Dhaka'])
        ->assertJsonPath('until', '2026-10-10T14:00:00-04:00');
});

it('finds midnight when the clocks change at it', function () {
    // Chile starts summer time on Sunday 6 September 2026: 00:00 becomes 01:00 (UTC-3).
    $this->travelTo(CarbonImmutable::parse('2026-09-05T16:00:00Z'));
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'America/Santiago'])
        ->assertJsonPath('until', '2026-09-06T04:00:00+00:00');
});

it('accepts the old names browsers still report', function () {
    // Chrome reports India as Asia/Calcutta: midnight there is 18:30 UTC.
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'Asia/Calcutta'])
        ->assertJsonPath('until', '2026-10-10T18:30:00+00:00');
});

it('falls back to the app timezone for a missing or unknown timezone', function (array $input) {
    config(['app.timezone' => 'Europe/London']);
    $this->loginWithSession($this->makeUser());

    // 21:00 in London (BST): its next midnight is 23:00 UTC.
    $this->postJson(route('mfa.nudge.dismiss'), $input)
        ->assertOk()
        ->assertJsonPath('until', '2026-10-10T00:00:00+01:00');
})->with([
    'missing' => [[]],
    'unknown' => [['timezone' => 'Mars/Olympus_Mons']],
    'not a string' => [['timezone' => ['Asia/Dhaka']]],
    'empty' => [['timezone' => '']],
]);

it('never takes the time from the client', function () {
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'UTC', 'until' => '2030-01-01T00:00:00Z'])
        ->assertJsonPath('until', '2026-10-10T00:00:00+00:00');
});

it('caps the dismissal at 26 hours and keeps it in the future', function () {
    $now = CarbonImmutable::parse('2026-10-09T20:00:00Z');

    expect(Nudge::cap($now, $now->addHours(30))->toIso8601String())->toBe('2026-10-10T22:00:00+00:00')
        ->and(Nudge::cap($now, $now->addHours(26))->toIso8601String())->toBe('2026-10-10T22:00:00+00:00')
        ->and(Nudge::cap($now, $now->addHours(3))->toIso8601String())->toBe('2026-10-09T23:00:00+00:00')
        // A midnight that isn't ahead (it can't be, but never store one).
        ->and(Nudge::cap($now, $now)->toIso8601String())->toBe('2026-10-10T22:00:00+00:00')
        ->and(Nudge::cap($now, $now->subHour())->toIso8601String())->toBe('2026-10-10T22:00:00+00:00');

    // One second before midnight: hidden for one second.
    expect(Nudge::nextMidnight(CarbonImmutable::parse('2026-10-09T23:59:59Z'), 'UTC')->toIso8601String())->toBe('2026-10-10T00:00:00+00:00');
});

it('stays dismissed in a new session and on other devices, until that midnight', function () use ($show) {
    $user = $this->makeUser();
    $this->loginWithSession($user);
    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'UTC'])->assertOk();

    // Signed out, then in again (or another browser): the cache still knows.
    $this->post('/logout');
    session()->flush();
    $this->freshGuards()->loginWithSession($user);
    expect(session()->has('mfa.nudge'))->toBeFalse()
        ->and($show())->toBeFalse()
        // Remembered in this session again, so the next page reads no cache.
        ->and(session()->has('mfa.nudge'))->toBeTrue();

    // Another user in the same browser still sees theirs.
    $this->freshGuards()->loginWithSession($this->makeUser());
    expect($show())->toBeTrue();

    $this->freshGuards()->loginWithSession($user);
    $this->travelTo(CarbonImmutable::parse('2026-10-10T00:00:01Z'));
    expect($show())->toBeTrue();
});

it('sees a dismissal from another device after its own has expired', function () use ($show) {
    $user = $this->makeUser();
    $this->loginWithSession($user);
    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'UTC'])->assertOk();

    // The next day, another device dismisses it again; this session still holds yesterday's.
    $this->travelTo(CarbonImmutable::parse('2026-10-10T09:00:00Z'));
    app(Nudge::class)->dismiss(new Store('other-device', new ArraySessionHandler(120)), $user, 'UTC');

    expect($show())->toBeFalse();
});

it('redirects back for Inertia and plain forms', function () {
    config(['mfa.ui.driver' => 'inertia']);
    $this->loginWithSession($this->makeUser());

    $this->from('/dashboard')->post(route('mfa.nudge.dismiss'), ['timezone' => 'UTC'], ['X-Inertia' => 'true'])
        ->assertStatus(303)
        ->assertRedirect('/dashboard')
        ->assertSessionMissing('mfa.status');
});

it('needs a login, like the other MFA routes', function () {
    $this->post(route('mfa.nudge.dismiss'))->assertRedirect('/login');
    $this->postJson(route('mfa.nudge.dismiss'))->assertUnauthorized();

    expect(Route::getRoutes()->getByName('mfa.nudge.dismiss')->gatherMiddleware())
        ->toEqual(Route::getRoutes()->getByName('mfa.password.confirm')->gatherMiddleware())
        ->toContain('web', 'auth');
});

it('is out of reach of a user who still has to pass the challenge', function () {
    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);

    $this->postJson(route('mfa.nudge.dismiss'))->assertForbidden()->assertJsonPath('error', 'mfa_required');
});

it('costs a verified user with MFA no query and no nudge cache read', function () {
    [$user] = $this->userWithFactor();
    $this->actingAsMfaVerified($user);
    $this->get('/app-page')->assertOk();

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'mfa_')) {
            $queries[] = $q->sql;
        }
    });
    $reads = [];
    Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
        if (str_contains($e->key, 'nudge')) {
            $reads[] = $e->key;
        }
    });

    $this->get('/app-page')->assertOk()->assertJsonPath('show', false);

    expect($queries)->toBeEmpty()->and($reads)->toBeEmpty();
});

it('reads the cache at most once per page, and not at all once the session knows', function () {
    $this->loginWithSession($this->makeUser());
    $reads = [];
    Event::listen([CacheHit::class, CacheMissed::class], function ($e) use (&$reads) {
        if (str_contains($e->key, 'nudge')) {
            $reads[] = $e->key;
        }
    });

    $this->get('/app-page')->assertJsonPath('show', true);
    expect($reads)->toHaveCount(1);

    $this->postJson(route('mfa.nudge.dismiss'), ['timezone' => 'UTC']);
    $reads = [];
    $this->get('/app-page')->assertJsonPath('show', false);
    $this->get('/app-page')->assertJsonPath('show', false);

    expect($reads)->toBeEmpty();
});

it('shows the same title and body on the settings page, to the same users', function () {
    $user = $this->makeUser();
    $this->loginWithSession($user);
    // Dismissing hides the floating card, not the settings page's notice.
    $this->postJson(route('mfa.nudge.dismiss'))->assertOk();

    $this->getJson(route('mfa.settings'))->assertOk()->assertJsonPath('nudge', [
        'title' => 'Protect your account',
        'body' => 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
    ]);

    $this->createMfaFactor($user);
    $this->actingAsMfaVerified($user)->getJson(route('mfa.settings'))->assertOk()->assertJsonPath('nudge', null);

    config(['mfa.enforcement.policy' => EnforceForEveryone::class]);
    $this->freshGuards()->loginWithSession($this->makeUser())->getJson(route('mfa.settings'))->assertOk()->assertJsonPath('mustEnroll', true)->assertJsonPath('nudge', null);

    config(['mfa.enforcement.policy' => null, 'mfa.nudge.enabled' => false]);
    $this->freshGuards()->loginWithSession($this->makeUser())->getJson(route('mfa.settings'))->assertOk()->assertJsonPath('nudge', null);
});

it('gives apps with an older published config the nudge keys', function () {
    config(['mfa' => ['enabled' => true]]);

    (new MfaServiceProvider(app()))->register();

    expect(config('mfa.nudge'))->toBe([
        'enabled' => true,
        'title' => 'Protect your account',
        'body' => 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
        'button' => 'Turn on',
        'dismiss_label' => 'Not today',
    ]);
});
