<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Session\SessionManager;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\ChallengeSteps;
use StrontiumCorp\LaravelMfa\Support\EnrollmentVerification;
use StrontiumCorp\LaravelMfa\Support\Nudge;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use StrontiumCorp\LaravelMfa\Support\VerificationLifetime;

/**
 * Clears MFA verification when a user logs out.
 *
 * Laravel's Auth::logout() leaves the rest of the session intact unless the
 * app also calls session()->invalidate() (artistly's admin logout doesn't),
 * which would otherwise let the next password login in the same browser skip
 * the challenge. The nudge's session mirror goes too: the next login asks
 * the cache, which holds the dismissal per user. So does a confirmed
 * password (auth.password_confirmed_at, the key Laravel's password.confirm
 * shares): it was this user's, and must not let the next login in the same
 * browser change factors without confirming their own. Likewise a proof of
 * ownership for adding a first factor (enrollment_verification) and its code.
 */
final class ForgetMfaStateOnLogout
{
    public function __construct(private readonly SessionManager $session) {}

    public function handle(Logout $event): void
    {
        $store = $this->session->driver();

        $store->forget([
            'mfa.verified', 'mfa.enroll', 'mfa.pending', 'mfa.flow_id', 'mfa.recovery_codes', Nudge::SESSION_PREFIX, Mfa::PASSWORD_CONFIRMED_AT,
            EnrollmentVerification::SESSION_PREFIX, EnrollmentVerification::CODE_KEY,
            TrustedBrowsers::SESSION_KEY, Mfa::REMINDER_DISMISSED,
            VerificationLifetime::SESSION_KEY, VerificationLifetime::SEEN_KEY, Mfa::IMPERSONATOR_PREFIX, ChallengeSteps::SESSION_KEY,
            // Only this guard's: another guard's login is still the time it logged in.
            ...($event->user === null ? [] : [Mfa::LOGIN_AT_PREFIX.'.'.$event->guard.'.'.$event->user->getAuthIdentifier()]),
        ]);
    }
}
