<?php

namespace StrontiumCorp\LaravelMfa\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use StrontiumCorp\LaravelMfa\Console\Concerns\ResolvesUser;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

/**
 * Support tool: "why can't this user log in?" in one command.
 */
class StatusCommand extends Command
{
    protected $signature = 'mfa:status
        {user : User id or email}
        {--guard= : Guard whose user provider to search}
        {--limit=20 : Number of recent audit events}
        {--flow= : Only show events from one flow id}';

    protected $description = "Show a user's MFA factors and recent MFA activity";

    use ResolvesUser;

    public function handle(Mfa $mfa, RecoveryCodes $recoveryCodes): int
    {
        if (! $user = $this->resolveUser()) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('User', $user::class.' #'.$user->getAuthIdentifier());
        $this->components->twoColumnDetail('Has active MFA (cached)', $mfa->hasConfirmedFactors($user) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Must enroll (policy)', $mfa->mustEnroll($user) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Recovery codes remaining', (string) $recoveryCodes->remaining($user));

        $this->newLine();
        $this->table(
            ['ID', 'Type', 'Label', 'Destination', 'Confirmed', 'Last used'],
            $user->mfaFactors()->orderBy('id')->get()->map(fn (MfaFactor $f) => [
                $f->id, $f->type->value, $f->label, $f->toPublicArray()['destination'] ?? '-',
                $f->confirmed_at?->toDateTimeString() ?? '<fg=yellow>pending</>',
                $f->last_used_at?->diffForHumans() ?? '-',
            ])->all(),
        );

        $logs = MfaAuditLog::query()
            ->where('authenticatable_type', $user instanceof Model ? $user->getMorphClass() : $user::class)
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->when($this->option('flow'), fn ($q, $flow) => $q->where('flow_id', $flow))
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->table(
            ['When', 'Event', 'Factor', 'Reason', 'Flow', 'IP'],
            $logs->map(fn (MfaAuditLog $log) => [
                $log->created_at->toDateTimeString(), $log->event, $log->factor_type ?? '-',
                $log->reason ? "<fg=red>{$log->reason}</>" : '-', $log->flow_id ? substr($log->flow_id, 0, 8) : '-', $log->ip_address ?? '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
