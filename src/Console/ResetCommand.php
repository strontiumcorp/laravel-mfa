<?php

namespace StrontiumCorp\LaravelMfa\Console;

use Illuminate\Console\Command;
use StrontiumCorp\LaravelMfa\Console\Concerns\ResolvesUser;
use StrontiumCorp\LaravelMfa\Mfa;

/**
 * Support tool for locked-out users (lost phone + lost recovery codes).
 * Verify the user's identity out-of-band before running it.
 */
class ResetCommand extends Command
{
    protected $signature = 'mfa:reset
        {user : User id or email}
        {--guard= : Guard whose user provider to search}
        {--force : Skip confirmation}';

    protected $description = "Remove all of a user's MFA factors and recovery codes";

    use ResolvesUser;

    public function handle(Mfa $mfa): int
    {
        if (! $user = $this->resolveUser()) {
            return self::FAILURE;
        }

        $count = $user->mfaFactors()->count();

        if (! $this->option('force') && ! $this->confirm("Remove {$count} factor(s) and all recovery codes for user #{$user->getAuthIdentifier()}?")) {
            return self::FAILURE;
        }

        // Also ends every session they verified in, on its next request.
        $mfa->reset($user, 'console:mfa:reset');

        $this->components->info("MFA reset for user #{$user->getAuthIdentifier()}.");

        return self::SUCCESS;
    }
}
