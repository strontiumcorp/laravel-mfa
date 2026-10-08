<?php

namespace StrontiumCorp\LaravelMfa\Console;

use Illuminate\Console\Command;
use StrontiumCorp\LaravelMfa\Console\Concerns\ResolvesUser;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

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

    public function handle(Mfa $mfa, RecoveryCodes $recoveryCodes): int
    {
        if (! $user = $this->resolveUser()) {
            return self::FAILURE;
        }

        $count = $user->mfaFactors()->count();

        if (! $this->option('force') && ! $this->confirm("Remove {$count} factor(s) and all recovery codes for user #{$user->getAuthIdentifier()}?")) {
            return self::FAILURE;
        }

        $user->mfaFactors()->get()->each(function (MfaFactor $factor) use ($user) {
            $factor->delete();
            event(new FactorDisabled($user, $factor->type, null, ['factor_id' => $factor->id, 'via' => 'console:mfa:reset']));
        });

        $recoveryCodes->clear($user);
        $mfa->forgetCachedState($user);

        $this->components->info("MFA reset for user #{$user->getAuthIdentifier()}.");

        return self::SUCCESS;
    }
}
