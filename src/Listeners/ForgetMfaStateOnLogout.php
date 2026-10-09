<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Session\SessionManager;
use StrontiumCorp\LaravelMfa\Support\Nudge;

/**
 * Clears MFA verification when a user logs out.
 *
 * Laravel's Auth::logout() leaves the rest of the session intact unless the
 * app also calls session()->invalidate() (artistly's admin logout doesn't),
 * which would otherwise let the next password login in the same browser skip
 * the challenge. The nudge's session mirror goes too: the next login asks
 * the cache, which holds the dismissal per user.
 */
final class ForgetMfaStateOnLogout
{
    public function __construct(private readonly SessionManager $session) {}

    public function handle(Logout $event): void
    {
        $store = $this->session->driver();

        $store->forget(['mfa.verified', 'mfa.enroll', 'mfa.pending', 'mfa.flow_id', 'mfa.recovery_codes', Nudge::SESSION_PREFIX]);
    }
}
