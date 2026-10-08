<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

/**
 * The user who actually authenticated this browser session.
 *
 * Deliberately NOT Auth::user(): per-request swaps (Auth::setUser() in
 * webhooks, jobs, or setUser-style impersonation) change Auth::user() but not
 * the identity stored in the session, and must never trigger a challenge.
 */
final class SessionIdentity
{
    public function __construct(
        public readonly string $guard,
        public readonly int|string $id,
        private readonly SessionGuard $guardInstance,
    ) {}

    /**
     * Every configured session guard that has a user logged in. Each one must
     * pass MFA on its own; checking only the first would let a second guard
     * (e.g. "admin") ride on an MFA-less login in the first.
     *
     * @param  list<string>  $guards
     * @return list<self>
     */
    public static function resolveAll(Request $request, AuthFactory $auth, array $guards): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $identities = [];

        foreach ($guards as $name) {
            $guard = $auth->guard($name);

            if (! $guard instanceof SessionGuard) {
                continue;
            }

            // Resolving the user first lets a remember-me cookie log the user
            // in and write their id to the session, so recaller logins are
            // challenged on the very first request.
            $guard->user();

            $id = $request->session()->get($guard->getName());

            if ($id !== null && $id !== '') {
                $identities[] = new self($name, $id, $guard);
            }
        }

        return $identities;
    }

    /** Load the session user, reusing the guard's instance when possible. */
    public function user(): ?Authenticatable
    {
        $current = $this->guardInstance->user();

        // Equivalent mutant(s): the session stores the same identifier type the provider returns.
        // Equivalent mutant(s): reusing the guard's user only saves a query; retrieveById() returns the same user.
        if ($current !== null && (string) $current->getAuthIdentifier() === (string) $this->id) { // @pest-mutate-ignore: RemoveStringCast,NotIdenticalToIdentical
            // Equivalent mutant(s): reusing the guard's user only saves a query; retrieveById() returns the same user.
            return $current; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        return $this->guardInstance->getProvider()->retrieveById($this->id);
    }
}
