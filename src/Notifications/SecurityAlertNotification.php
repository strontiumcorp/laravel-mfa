<?php

namespace StrontiumCorp\LaravelMfa\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tells the account owner that their two-factor setup changed or looks
 * attacked (config mfa.notifications). Swap via
 * config('mfa.notifications.notification'); your class receives the same
 * constructor arguments. Holds no codes or secrets.
 *
 * $alert: factor_enabled | factor_disabled | recovery_codes_generated |
 *         recovery_code_used | suspicious_code_requests | enrollment_link_issued
 * $details: factor (the method's label) | null, ip | null, occurred_at
 *           (ISO 8601), by_administrator (bool), remaining (recovery codes
 *           left, for recovery_code_used) | null
 */
class SecurityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array{factor?: string|null, ip?: string|null, occurred_at?: string, by_administrator?: bool, remaining?: int|null} $details */
    public function __construct(
        public readonly string $alert,
        public readonly array $details = [],
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $app = (string) config('app.name');
        $factor = $this->details['factor'] ?? __('A sign-in method');

        [$subject, $lines] = match ($this->alert) {
            'factor_enabled' => [
                __('A sign-in method was added to your :app account', ['app' => $app]),
                [__(':factor was added to your account for two-factor sign-in.', ['factor' => $factor])],
            ],
            'factor_disabled' => [
                __('A sign-in method was removed from your :app account', ['app' => $app]),
                [($this->details['by_administrator'] ?? false)
                    ? __('An administrator removed :factor from your account.', ['factor' => $factor])
                    : __(':factor was removed from your account.', ['factor' => $factor])],
            ],
            'recovery_codes_generated' => [
                __('New recovery codes for your :app account', ['app' => $app]),
                [__('New recovery codes were created for your account. Your previous recovery codes no longer work.')],
            ],
            'enrollment_link_issued' => [
                __('A two-factor setup link was created for your :app account', ['app' => $app]),
                [($this->details['by_administrator'] ?? false)
                    ? __('An administrator created a link that lets your account add its first sign-in method without an email code.')
                    : __('A link was created that lets your account add its first sign-in method without an email code.')],
            ],
            'recovery_code_used' => [
                __('A recovery code was used on your :app account', ['app' => $app]),
                array_filter([
                    __('Someone signed in to your account with one of your recovery codes.'),
                    isset($this->details['remaining']) ? __('Recovery codes left: :count.', ['count' => $this->details['remaining']]) : null,
                ]),
            ],
            default => [
                __('Sign-in codes keep being requested for your :app account', ['app' => $app]),
                [__('Someone signed in with your password and keeps asking for sign-in codes without using them.')],
            ],
        };

        $message = (new MailMessage)->subject($subject);

        foreach ($lines as $line) {
            $message->line($line);
        }

        if (isset($this->details['occurred_at'])) {
            $message->line(__('When: :time', ['time' => Carbon::parse($this->details['occurred_at'])->timezone((string) config('app.timezone'))->format('j M Y, H:i T')]));
        }

        if (! empty($this->details['ip'])) {
            $message->line(__('IP address: :ip', ['ip' => $this->details['ip']]));
        }

        return $message->line($this->alert === 'suspicious_code_requests'
            ? __('If this was not you, change your password now: someone else knows it.')
            : __('If this was you, there is nothing to do. If it was not, change your password now and contact support.'));
    }
}
