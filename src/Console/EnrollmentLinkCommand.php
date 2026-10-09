<?php

namespace StrontiumCorp\LaravelMfa\Console;

use Illuminate\Console\Command;
use StrontiumCorp\LaravelMfa\Console\Concerns\ResolvesUser;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\EnrollmentLinks;
use StrontiumCorp\LaravelMfa\Support\EnrollmentVerification;

/**
 * Support tool: a one-time link that lets a user add their first factor
 * without an email code (enrollment_verification). Verify the user's
 * identity out-of-band first, and hand the link over on a channel you trust:
 * with their password, it is enough to take over two-factor setup.
 */
class EnrollmentLinkCommand extends Command
{
    protected $signature = 'mfa:enrollment-link
        {user : User id or email}
        {--guard= : Guard whose user provider to search}
        {--minutes= : How long the link stays valid (default: enrollment_verification.link_ttl)}';

    protected $description = 'Create a one-time link that lets a user add their first MFA factor without an email code';

    use ResolvesUser;

    public function handle(Mfa $mfa, EnrollmentVerification $verification, EnrollmentLinks $links): int
    {
        if (! $user = $this->resolveUser()) {
            return self::FAILURE;
        }

        $minutes = $this->option('minutes');

        if ($minutes !== null && (! ctype_digit((string) $minutes) || (int) $minutes < 1)) {
            $this->components->error('--minutes must be a whole number of minutes, at least 1.');

            return self::FAILURE;
        }

        if ($mfa->hasConfirmedFactors($user)) {
            $this->components->warn("User #{$user->getAuthIdentifier()} already has a factor: the link isn't needed (it only helps add the first one).");
        } elseif (! $verification->appliesTo($user)) {
            $this->components->warn("User #{$user->getAuthIdentifier()} doesn't need verification to add a factor (enrollment_verification.required_for).");
        }

        $minutes = $minutes === null ? (int) config('mfa.enrollment_verification.link_ttl') : (int) $minutes;
        $url = $links->issue($user, $minutes, 'console:mfa:enrollment-link', byAdministrator: true);

        $this->components->info("One-time setup link for user #{$user->getAuthIdentifier()}, valid for {$minutes} minute(s), until their password changes:");
        $this->line($url);
        $this->newLine();
        $this->line('They must open it while signed in to their own account. Send it on a channel you trust.');

        return self::SUCCESS;
    }
}
