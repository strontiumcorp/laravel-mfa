<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\URL;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\EnrollmentLinkProblem;
use StrontiumCorp\LaravelMfa\Events\EnrollmentLinkIssued;

/**
 * An administrator's one-time link that proves ownership before a first
 * factor (see EnrollmentVerification), for users without an email address
 * or when email codes are off because the mailbox can't be trusted. Signed,
 * one use, only in the user's own signed-in session, and revoked by a
 * password change.
 */
final class EnrollmentLinks
{
    /** EnrollmentLinkIssued "via" when the app called Mfa::enrollmentLink() itself. */
    public const VIA_APP = 'app';

    public function __construct(
        private readonly EnrollmentVerification $verification,
        private readonly CodeHasher $hasher,
        private readonly Cache $cache,
        private readonly Dispatcher $events,
    ) {}

    /**
     * A link for this user, valid for $minutes (enrollment_verification.link_ttl
     * by default). $via says where it was issued (VIA_APP, or e.g.
     * "console:mfa:enrollment-link"); $byAdministrator marks it as issued by
     * an administrator for the owner's notification.
     */
    public function issue(MultiFactorAuthenticatable $user, ?int $minutes = null, string $via = self::VIA_APP, bool $byAdministrator = false): string
    {
        $expiresAt = now()->addMinutes($minutes ?? (int) config('mfa.enrollment_verification.link_ttl'));

        $url = URL::temporarySignedRoute('mfa.enrollment-verification.link', $expiresAt, [
            'user' => $user->getAuthIdentifier(),
            'binding' => $this->binding($user),
        ]);

        $this->events->dispatch(new EnrollmentLinkIssued($user, null, null, [
            'expires_at' => $expiresAt->toIso8601String(),
            'via' => $via,
            ...($byAdministrator ? ['by_administrator' => true] : []),
        ]));

        return $url;
    }

    /**
     * Redeem a link from issue() (its signature and expiry are already
     * checked by the route): it must be for this user, issued since their
     * last password change, and unused. null = redeemed.
     */
    public function redeem(Session $session, MultiFactorAuthenticatable $user, string $userId, string $binding, string $signature, int $expires): ?EnrollmentLinkProblem
    {
        if ($userId !== (string) $user->getAuthIdentifier()) {
            return EnrollmentLinkProblem::OtherAccount;
        }

        if (! $this->hasher->matches((string) $user->getAuthPassword(), $binding, $this->scope($user))) {
            return EnrollmentLinkProblem::Revoked;
        }

        if (! $this->cache->add(CacheKey::for('enrollment-link-used', $signature), true, max(1, $expires - now()->getTimestamp()))) {
            return EnrollmentLinkProblem::Used;
        }

        $this->verification->markVerified($session, $user, 'link');

        return null;
    }

    /** Ties a link to the password it was issued under: changing the password revokes it. */
    private function binding(MultiFactorAuthenticatable $user): string
    {
        return $this->hasher->hash((string) $user->getAuthPassword(), $this->scope($user));
    }

    private function scope(MultiFactorAuthenticatable $user): string
    {
        return 'enrollment-link:'.$user->getAuthIdentifier();
    }
}
