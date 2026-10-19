<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Auth\Events\OtherDeviceLogout;
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
 * ends them too (passwordChanged()), except that "sign out of other
 * devices" keeps the browser that asked for it (otherDevicesSignedOut()).
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

        if ($user instanceof Model && ! $eventOrUser instanceof PasswordReset) {
            $column = method_exists($user, 'getAuthPasswordName') ? $user->getAuthPasswordName() : 'password';

            if (! $user->wasChanged($column)) {
                return;
            }

            // A save may be "sign out of other devices", which re-hashes an unchanged
            // password: note this request's browser first, so it can stay trusted.
            if (config('mfa.trusted_browsers.enabled')) {
                $this->attempt(fn () => $this->browsers->setAsideCurrent($user, (string) $user->getOriginal($column)));
            }
        }

        $this->forget($user, TrustedBrowserRevocation::PasswordChanged);
    }

    /**
     * Laravel's "sign out of other devices" (OtherDeviceLogout), after the
     * password re-hash above ended every trusted browser: the one that asked
     * for it stays trusted, if it was.
     */
    public function otherDevicesSignedOut(OtherDeviceLogout $event): void
    {
        if ($event->user instanceof MultiFactorAuthenticatable && config('mfa.trusted_browsers.enabled')) {
            $this->attempt(fn () => $this->browsers->keepCurrent($event->user, $event->guard));
        }
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

        $this->attempt(fn () => $this->browsers->forget($user, $cause));
    }

    /** Runs inside the app's own save or logout: a failure is reported, never thrown. */
    private function attempt(\Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
