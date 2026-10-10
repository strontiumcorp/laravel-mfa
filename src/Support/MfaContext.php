<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Everything the frontend needs to know about MFA, as one value. Share it
 * with every Inertia page under a single key:
 *
 *   // HandleInertiaRequests::share()
 *   'mfa' => fn () => Mfa::context($request),
 *
 * Mirrored by the MfaContext type in the published {Pages|pages}/mfa/mfa-context.ts;
 * the shape is pinned by tests/Feature/MfaContextTest.php. Change both together.
 *
 * @phpstan-type NudgeShape array{show: bool, title: string, body: string, button: string, dismissLabel: string, dismissUrl: string|null}
 * @phpstan-type ReverifyReminder array{show: bool, reason: 'trust'|'lifetime'|null, expiresAt: string|null, showAt: string|null, title: string, body: string, button: string, dismissLabel: string, verifyUrl: string|null, dismissUrl: string|null}
 * @phpstan-type VerificationShape array{profile: string, now: string, expiresAt: string|null, remindAt: string|null, graceUntil: string|null, idleSeconds: int|null, idleExpiresAt: string|null, renewUrl: string|null, keepAliveUrl: string|null, stateUrl: string|null}
 *
 * @implements Arrayable<string, mixed>
 */
final class MfaContext implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $factors  enabled factor types
     * @param  array{hasMfa: bool, verified: bool, mustEnroll: bool}|null  $user  null for guests
     * @param  array{settings: string|null, challenge: string|null}  $urls  null when MFA routes are off
     * @param  NudgeShape  $nudge  the turn-on-two-factor nudge (config mfa.nudge)
     * @param  ReverifyReminder  $reverifyReminder  verify early before the session's verification ends: a trusted browser's trust (mfa.trusted_browsers.reminder) or the lifetime window (mfa.lifetime)
     * @param  VerificationShape|null  $verification  the verified session's deadlines (mfa.lifetime), null when it has none
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $factors,
        public readonly bool $passwordConfirmation,
        public readonly ?array $user,
        public readonly array $urls,
        public readonly array $nudge,
        public readonly array $reverifyReminder,
        public readonly ?array $verification,
    ) {}

    /**
     * @return array{enabled: bool, factors: list<string>, passwordConfirmation: bool, user: array{hasMfa: bool, verified: bool, mustEnroll: bool}|null, urls: array{settings: string|null, challenge: string|null}, nudge: NudgeShape, reverifyReminder: ReverifyReminder, verification: VerificationShape|null}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'factors' => $this->factors,
            'passwordConfirmation' => $this->passwordConfirmation,
            'user' => $this->user,
            'urls' => $this->urls,
            'nudge' => $this->nudge,
            'reverifyReminder' => $this->reverifyReminder,
            'verification' => $this->verification,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
