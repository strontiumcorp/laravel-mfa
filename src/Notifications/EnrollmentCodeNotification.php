<?php

namespace StrontiumCorp\LaravelMfa\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The code that proves inbox access before an account's first factor is
 * added (enrollment_verification). Swap via
 * config('mfa.enrollment_verification.notification'); your class receives
 * the same constructor arguments. Queued on the delivery queue when one is
 * set (encrypted: the payload holds the code), sent inline otherwise.
 */
class EnrollmentCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly int $ttlSeconds,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $minutes = max(1, intdiv($this->ttlSeconds, 60));

        return (new MailMessage)
            ->subject(__('Confirm two-factor setup for your :app account', ['app' => config('app.name')]))
            ->line(__('Someone signed in to your account and is setting up two-factor sign-in. To check that it is you, enter this code:'))
            ->line('**'.$this->code.'**')
            ->line(__('This code expires in :minutes minutes.', ['minutes' => $minutes]))
            ->line(__('If this was not you, do not share the code with anyone: change your password now, because someone else knows it.'));
    }
}
