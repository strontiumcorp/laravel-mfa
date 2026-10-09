<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\TrustedBrowserRevocation;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Events\FactorEnabled;
use StrontiumCorp\LaravelMfa\Events\MfaEvent;
use StrontiumCorp\LaravelMfa\Events\RecoveryCodeUsed;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use Throwable;

/**
 * A change to how someone proves it's them ends every trusted browser
 * (trusted_browsers): a method added or removed (mfa:reset included), or a
 * recovery code used, which often means a lost device. A new password
 * ends them too (passwordChanged()).
 */
final class ForgetTrustedBrowsers
{
    public const EVENTS = [
        FactorEnabled::class => TrustedBrowserRevocation::FactorEnabled,
        FactorDisabled::class => TrustedBrowserRevocation::FactorDisabled,
        RecoveryCodeUsed::class => TrustedBrowserRevocation::RecoveryCodeUsed,
    ];

    public function __construct(private readonly TrustedBrowsers $browsers) {}

    public function handle(MfaEvent $event): void
    {
        if ($event->user instanceof MultiFactorAuthenticatable && isset(self::EVENTS[$event::class])) {
            $this->forget($event->user, self::EVENTS[$event::class]);
        }
    }

    /**
     * A new password ends every trusted browser at once: on Laravel's
     * PasswordReset, and when the MFA user model is saved with a changed
     * password ("eloquent.updated: {model}", which passes the model).
     */
    public function passwordChanged(object $eventOrUser): void
    {
        $user = $eventOrUser instanceof PasswordReset ? $eventOrUser->user : $eventOrUser;

        if (! $user instanceof MultiFactorAuthenticatable) {
            return;
        }

        if ($user instanceof Model && ! $eventOrUser instanceof PasswordReset
            && ! $user->wasChanged(method_exists($user, 'getAuthPasswordName') ? $user->getAuthPasswordName() : 'password')) {
            return;
        }

        $this->forget($user, TrustedBrowserRevocation::PasswordChanged);
    }

    /**
     * Nothing while the feature is off (no query). It runs inside the app's
     * own save, enrollment or reset, so a failure (say, the migration hasn't
     * run) is reported and never fails them; a browser left behind still
     * stops counting at its next use (TrustedBrowsers::attempt()).
     */
    private function forget(MultiFactorAuthenticatable $user, TrustedBrowserRevocation $cause): void
    {
        if (! config('mfa.trusted_browsers.enabled')) {
            return;
        }

        try {
            $this->browsers->forget($user, $cause);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
