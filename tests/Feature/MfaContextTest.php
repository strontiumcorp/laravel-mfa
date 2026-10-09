<?php

use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\EnforceForEveryone;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\ExemptSocialLogins;

// Pins the shape mirrored by stubs/inertia-react/pages/mfa-context.ts.
// Change both together.

it('describes a guest', function () {
    expect(Mfa::context()->toArray())->toBe([
        'enabled' => true,
        'factors' => ['totp', 'email', 'sms'],
        'passwordConfirmation' => false,
        'user' => null,
        'urls' => ['settings' => route('mfa.settings'), 'challenge' => route('mfa.challenge')],
        'nudge' => [
            'show' => false,
            'title' => 'Protect your account',
            'body' => 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
            'button' => 'Turn on',
            'dismissLabel' => 'Not today',
            'dismissUrl' => route('mfa.nudge.dismiss'),
        ],
    ]);
});

it('offers the nudge to a session user without MFA (tests/Feature/NudgeTest.php)', function () {
    $this->loginWithSession($this->makeUser());

    expect(Mfa::context(request()->setLaravelSession(session()->driver()))->nudge)->toMatchArray(['show' => true]);

    config(['mfa.routes.enabled' => false]);
    expect(Mfa::context(request()->setLaravelSession(session()->driver()))->nudge)->toMatchArray(['show' => false, 'dismissUrl' => null]);
});

it('describes the session user', function () {
    config(['mfa.enforcement.roles' => ['admin'], 'mfa.routes.password_confirmation' => true]);
    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);
    $request = request()->setLaravelSession(session()->driver());

    expect(Mfa::context($request)->toArray())->toMatchArray([
        'passwordConfirmation' => true,
        'user' => ['hasMfa' => true, 'verified' => false, 'mustEnroll' => false],
    ]);

    $this->actingAsMfaVerified($user);
    expect(Mfa::context($request)->user)->toBe(['hasMfa' => true, 'verified' => true, 'mustEnroll' => false]);
});

it('flags users who must enroll', function () {
    config(['mfa.enforcement.policy' => EnforceForEveryone::class]);
    $this->loginWithSession($this->makeUser());

    expect(Mfa::context(request()->setLaravelSession(session()->driver()))->user)
        ->toBe(['hasMfa' => false, 'verified' => false, 'mustEnroll' => true]);
});

it('says whether the session user would be asked for their password', function () {
    config(['mfa.routes.password_confirmation' => true, 'mfa.routes.password_confirmation_policy' => ExemptSocialLogins::class]);
    $request = fn () => request()->setLaravelSession(session()->driver());

    // A guest: whether anyone may be asked.
    expect(Mfa::context($request())->passwordConfirmation)->toBeTrue();

    $this->loginWithSession($this->makeUser(['name' => 'Google user']));
    expect(Mfa::context($request())->passwordConfirmation)->toBeFalse();

    $this->freshGuards()->loginWithSession($this->makeUser());
    expect(Mfa::context($request())->passwordConfirmation)->toBeTrue();

    // The app's own middleware may ask anyone.
    $this->freshGuards()->loginWithSession($this->makeUser(['name' => 'Google user']));
    config(['mfa.routes.confirm_middleware' => ['password.confirm']]);
    expect(Mfa::context($request())->passwordConfirmation)->toBeTrue();
});

it('serialises as JSON for Inertia shared props', function () {
    config(['mfa.enabled' => false]);

    expect(json_decode(json_encode(Mfa::context()), true))->toMatchArray(['enabled' => false, 'user' => null]);
});
