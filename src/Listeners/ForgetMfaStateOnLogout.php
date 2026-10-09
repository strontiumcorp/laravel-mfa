<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Session\SessionManager;

/**
 * Clears MFA verification when a user logs out.
 *
 * Laravel's Auth::logout() leaves the rest of the session intact unless the
 * app also calls session()->invalidate() (artistly's admin logout doesn't),
 * which would otherwise let the next password login in the same browser skip
 * the challenge.
 */
final class ForgetMfaStateOnLogout
{
    public function __construct(private readonly SessionManager $session) {}

    public function handle(Logout $event): void
    {
        $store = $this->session->driver();

        $store->forget(['mfa.verified', 'mfa.enroll', 'mfa.pending', 'mfa.flow_id', 'mfa.recovery_codes']);
    }
}
