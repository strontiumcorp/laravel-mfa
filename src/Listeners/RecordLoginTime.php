<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\ChallengeSteps;

/**
 * Notes when each guard logged its user in (password, Socialite, remember-me
 * or a login swap all fire Login), so an administrator's reset
 * (Mfa::reset()) can end every login made before it. A session without it
 * (logged in before this existed) counts as older than any reset.
 */
final class RecordLoginTime
{
    public function __construct(private readonly SessionManager $session) {}

    public function handle(Login $event): void
    {
        // Only a login in a request with a session: a queue worker or console
        // login has nothing to record, and an unsaved store would only grow.
        $container = Container::getInstance();
        $request = $container->bound('request') ? $container->make('request') : null;
        if (! $request instanceof Request || ! $request->hasSession()) {
            return;
        }

        $id = $event->user->getAuthIdentifier();
        $session = $this->session->driver();

        $session->put(Mfa::LOGIN_AT_PREFIX.'.'.$event->guard.'.'.$id, now()->getTimestamp());
        // A step passed in an earlier login never counts for this one.
        $session->forget(ChallengeSteps::SESSION_KEY.'.'.$event->guard.'.'.$id);
    }
}
