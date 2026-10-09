<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\ChallengeDeliveryFailed;
use StrontiumCorp\LaravelMfa\Events\EnrollmentVerificationSent;
use StrontiumCorp\LaravelMfa\Events\EnrollmentVerified;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Notifications\EnrollmentCodeNotification;
use Throwable;

/**
 * Proof, beyond the password, before an account's first factor is added
 * (config mfa.enrollment_verification).
 *
 * Without it, someone who has only the password of an account that must
 * enroll (or of any account without a factor, with "everyone") can add
 * their own authenticator app, and the account is then theirs: the owner is
 * challenged for a factor they don't have. Two proofs are accepted:
 *
 *  - a code sent to the account's email address (getMfaEmail()), entered in
 *    the same session: proves access to the inbox, not just the password;
 *  - a one-time link an administrator issued (EnrollmentLinks), for users
 *    without an email address, or for every user when email codes are off
 *    (email => false) because the mailbox can't be trusted.
 *
 * The proof is a session flag per user, cleared on logout. The pending code
 * lives in the session too (as an HMAC), so it only works in the browser
 * that asked for it; its attempts are counted atomically in the cache.
 */
final class EnrollmentVerification
{
    /** Session flag: this session proved it owns the account (per user id). */
    public const SESSION_PREFIX = 'mfa.enrollment_verified';

    /** Session: the pending email code (HMAC, expiry, attempts key). */
    public const CODE_KEY = 'mfa.enrollment_code';

    private const HOUR = 3600;

    public function __construct(
        private readonly Mfa $mfa,
        private readonly CodeGenerator $generator,
        private readonly CodeHasher $hasher,
        private readonly SendGuard $guard,
        private readonly RateLimits $limits,
        private readonly RateLimiter $limiter,
        private readonly Cache $cache,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Whether this user must prove ownership before adding a factor:
     * enrollment_verification.required_for is "enforced" (and an enforcement
     * rule applies to them) or "everyone", and they have no confirmed factor
     * yet. One cached lookup; called only on the enrollment routes.
     */
    public function appliesTo(MultiFactorAuthenticatable $user): bool
    {
        $mode = config('mfa.enrollment_verification.required_for');

        if (! in_array($mode, ['enforced', 'everyone'], true) || $this->mfa->hasConfirmedFactors($user)) {
            return false;
        }

        return $mode === 'everyone' || $this->mfa->isEnforced($user);
    }

    /** Whether this session must still prove ownership before adding a factor. */
    public function required(Session $session, MultiFactorAuthenticatable $user): bool
    {
        return $this->appliesTo($user) && ! $this->isVerified($session, $user);
    }

    public function isVerified(Session $session, MultiFactorAuthenticatable $user): bool
    {
        return $session->has($this->sessionKey($user));
    }

    /**
     * Where the email code goes: the account's address, unless email codes
     * are off (then only an administrator's link proves ownership).
     */
    public function email(MultiFactorAuthenticatable $user): ?string
    {
        $email = config('mfa.enrollment_verification.email') ? $user->getMfaEmail() : null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * For the settings page and the "verification required" answer: where
     * the code would go (masked; null = only a link works).
     *
     * @return array{email: string|null}
     */
    public function describe(MultiFactorAuthenticatable $user): array
    {
        $email = $this->email($user);

        return ['email' => $email === null ? null : Mask::email($email)];
    }

    /**
     * Send a code to the account's email address, bound to this session.
     * Waits like a login code (the email factor's resend_cooldown curve over
     * the last hour) and counts toward the account's send caps, so it can't
     * be used to flood the owner's inbox.
     */
    public function send(Session $session, MultiFactorAuthenticatable $user): VerificationResult
    {
        $email = $this->email($user);

        if ($email === null) {
            return VerificationResult::failure(FailureReason::EnrollmentLinkRequired);
        }

        $options = $this->options();
        $sentKey = CacheKey::for('enrollment-code-sent', CacheKey::user($user));
        $streakKey = CacheKey::for('enrollment-code-streak', CacheKey::user($user));
        $streak = $this->limiter->attempts($streakKey);
        $lastSent = $this->cache->get($sentKey);

        // Numeric, not int: Redis hands numbers back as strings.
        if (is_numeric($lastSent)) {
            $readyAt = (int) $lastSent + Cooldown::capped($streak, $options['resend_cooldown'], $options['ttl']);

            if ($readyAt > now()->getTimestamp()) {
                return VerificationResult::failure(FailureReason::Cooldown, ['retry_after' => $readyAt - now()->getTimestamp()]);
            }
        }

        if (($refused = $this->guard->attemptAccount($user, FactorType::Email, Context::getHidden(RequestContext::IP))) !== null) {
            return $refused;
        }

        $this->limiter->hit($streakKey, self::HOUR);
        $this->cache->put($sentKey, now()->getTimestamp(), self::HOUR);

        $code = $this->generator->otp($options['length']);
        $nonce = Str::random(32);
        $session->put(self::CODE_KEY, [
            'user' => (string) $user->getAuthIdentifier(),
            'nonce' => $nonce,
            'hash' => $this->hasher->hash($code, $this->scope($user, $nonce)),
            'expires_at' => now()->getTimestamp() + $options['ttl'],
        ]);

        $queued = DeliveryQueue::queued();

        try {
            /** @var class-string<EnrollmentCodeNotification> $class */
            $class = config('mfa.enrollment_verification.notification');
            DeliveryQueue::send($email, new $class($code, $options['ttl']));
        } catch (Throwable $e) {
            report($e);

            // Never sent: drop the code and the wait it started.
            $session->forget(self::CODE_KEY);
            $this->cache->forget($sentKey);
            $this->limiter->decrement($streakKey, self::HOUR);

            $this->events->dispatch(new ChallengeDeliveryFailed($user, FactorType::Email, FailureReason::DeliveryFailed, [
                'stage' => 'enrollment_verification',
                // The class only: transport messages can contain the recipient.
                'error' => '[email] '.$e::class,
            ]));

            return VerificationResult::failure(FailureReason::DeliveryFailed);
        }

        $this->events->dispatch(new EnrollmentVerificationSent($user, FactorType::Email, null, ['queued' => $queued]));

        return VerificationResult::success([
            'retry_after' => Cooldown::capped($streak + 1, $options['resend_cooldown'], $options['ttl']),
        ]);
    }

    /**
     * Check the code sent to this session. Counts toward the user's verify
     * limits; the code is burned after factors.email.max_attempts wrong
     * guesses (counted atomically, so parallel guesses can't exceed it).
     */
    public function verify(Session $session, MultiFactorAuthenticatable $user, string $code): VerificationResult
    {
        if (! $this->limits->attemptVerify($user)) {
            return VerificationResult::failure(FailureReason::RateLimited, ['retry_after' => $this->limits->verifyAvailableIn($user)]);
        }

        $pending = $session->get(self::CODE_KEY);

        if (! is_array($pending) || ($pending['user'] ?? null) !== (string) $user->getAuthIdentifier()) {
            return VerificationResult::failure(FailureReason::NoActiveCode);
        }

        if ((int) $pending['expires_at'] <= now()->getTimestamp()) {
            $session->forget(self::CODE_KEY);

            return VerificationResult::failure(FailureReason::Expired);
        }

        $max = $this->options()['max_attempts'];
        $attempts = $this->limiter->hit(CacheKey::for('enrollment-code-attempts', $pending['nonce']), self::HOUR);
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($attempts <= $max && $this->hasher->matches($code, (string) $pending['hash'], $this->scope($user, (string) $pending['nonce']))) {
            $session->forget(self::CODE_KEY);
            $this->limits->clearVerify($user);
            $this->markVerified($session, $user, 'email');

            return VerificationResult::success();
        }

        if ($attempts >= $max) {
            $session->forget(self::CODE_KEY);

            return VerificationResult::failure(FailureReason::TooManyAttempts);
        }

        return VerificationResult::failure(FailureReason::InvalidCode, ['attempts_remaining' => $max - $attempts]);
    }

    /**
     * Record that this session proved it owns the account ("email": the code;
     * "link": an administrator's link, see EnrollmentLinks).
     */
    public function markVerified(Session $session, MultiFactorAuthenticatable $user, string $method): void
    {
        $session->put($this->sessionKey($user), now()->getTimestamp());

        $this->events->dispatch(new EnrollmentVerified($user, $method === 'email' ? FactorType::Email : null, null, ['method' => $method]));
    }

    private function scope(MultiFactorAuthenticatable $user, string $nonce): string
    {
        return 'enrollment:'.$user->getAuthIdentifier().':'.$nonce;
    }

    private function sessionKey(MultiFactorAuthenticatable $user): string
    {
        return self::SESSION_PREFIX.'.'.$user->getAuthIdentifier();
    }

    /** @return array{length: int, ttl: int, max_attempts: int, resend_cooldown: int|array<string, int|float>} */
    private function options(): array
    {
        $email = (array) config('mfa.factors.email');

        return [
            'length' => (int) $email['length'],
            'ttl' => (int) $email['ttl'],
            'max_attempts' => (int) $email['max_attempts'],
            'resend_cooldown' => $email['resend_cooldown'],
        ];
    }
}
