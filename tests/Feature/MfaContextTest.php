<?php

use StrontiumCorp\LaravelMfa\Facades\Mfa;

// Pins the shape mirrored by stubs/inertia-react/pages/mfa-context.ts.
// Change both together.

it('describes a guest', function () {
    expect(Mfa::context()->toArray())->toBe([
        'enabled' => true,
        'factors' => ['totp', 'email', 'sms'],
        'passwordConfirmation' => false,
        'user' => null,
        'urls' => ['settings' => route('mfa.settings'), 'challenge' => route('mfa.challenge')],
    ]);
});

it('describes the session user', function () {
    config(['mfa.enforcement.roles' => ['admin'], 'mfa.routes.confirm_middleware' => ['password.confirm']]);
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
    config(['mfa.enforcement.roles' => ['admin']]);
    $user = $this->makeUser();
    $this->loginWithSession($user);
    Mfa::enforceUsing(fn () => true);

    try {
        expect(Mfa::context(request()->setLaravelSession(session()->driver()))->user)
            ->toBe(['hasMfa' => false, 'verified' => false, 'mustEnroll' => true]);
    } finally {
        Mfa::enforceUsing(null);
    }
});

it('serialises as JSON for Inertia shared props', function () {
    config(['mfa.enabled' => false]);

    expect(json_decode(json_encode(Mfa::context()), true))->toMatchArray(['enabled' => false, 'user' => null]);
});
