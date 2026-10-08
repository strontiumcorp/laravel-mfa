<?php

namespace StrontiumCorp\LaravelMfa\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Swap via config('mfa.factors.email.notification') to customise the email.
 * Your class receives the same constructor arguments.
 */
class OtpCodeNotification extends Notification
{
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
            ->subject(__('Your :app verification code', ['app' => config('app.name')]))
            ->line(__('Your verification code is:'))
            ->line('**'.$this->code.'**')
            ->line(__('This code expires in :minutes minutes.', ['minutes' => $minutes]))
            ->line(__('If you did not try to sign in, you can ignore this email — but consider changing your password.'));
    }
}
