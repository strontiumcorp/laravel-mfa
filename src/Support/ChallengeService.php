<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Events\Dispatcher;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\RecoveryCodeUsed;
use StrontiumCorp\LaravelMfa\Events\VerificationFailed;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;

/**
 * Orchestrates the login challenge: rate limiting, factor lookup, delivery,
 * verification and the events for each outcome. Controllers stay thin.
 */
final class ChallengeService
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly RateLimits $limits,
        private readonly Dispatcher $events,
    ) {}

    /** @param bool $confirmed false = resend during enrollment */
    public function send(MultiFactorAuthenticatable $user, int|string $factorId, bool $confirmed = true): VerificationResult
    {
        $factor = $this->findFactor($user, $factorId, $confirmed);

        if ($factor === null) {
            return $this->fail($user, null, FailureReason::FactorNotFound, ['factor_id' => $factorId, 'stage' => 'send']);
        }

        if (! $factor->type->isDelivered()) {
            // Equivalent mutant(s): TOTP's challenge() is a successful no-op, so falling through gives the same result.
            return VerificationResult::success(); // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $result = $this->mfa->factor($factor->type)->challenge($factor);

        if ($result->failed() && $result->reason !== FailureReason::DeliveryFailed) {
            // Delivery failures already emitted ChallengeDeliveryFailed.
            $this->events->dispatch(new VerificationFailed($user, $factor->type, $result->reason, ['stage' => 'send', ...$result->context]));
        }

        return $result;
    }

    public function verify(MultiFactorAuthenticatable $user, int|string $factorId, string $code): VerificationResult
    {
        $factor = $this->findFactor($user, $factorId);

        if ($factor === null) {
            return $this->fail($user, null, FailureReason::FactorNotFound, ['factor_id' => $factorId]);
        }

        return $this->verifyFactor($user, $factor, $code);
    }

    /** Shared by the login challenge and enrollment confirmation. */
    public function verifyFactor(MultiFactorAuthenticatable $user, MfaFactor $factor, string $code, string $stage = 'challenge'): VerificationResult
    {
        if (! $this->limits->attemptVerify($user)) {
            return $this->fail($user, $factor->type, FailureReason::RateLimited, [
                'stage' => $stage, 'retry_after' => $this->limits->verifyAvailableIn($user),
            ]);
        }

        $result = $this->mfa->factor($factor->type)->verify($factor, $code);

        if ($result->failed()) {
            // Equivalent mutant(s): a failed result always carries a reason.
            return $this->fail($user, $factor->type, $result->reason ?? FailureReason::InvalidCode, [ // @pest-mutate-ignore: CoalesceRemoveLeft
                'stage' => $stage, 'factor_id' => $factor->getKey(), ...$result->context,
            ]);
        }

        $this->limits->clearVerify($user);
        $factor->forceFill(['last_used_at' => now()])->save();

        return VerificationResult::success(['factor_id' => $factor->getKey(), 'factor' => $factor->type]);
    }

    public function recover(MultiFactorAuthenticatable $user, string $code): VerificationResult
    {
        if (! $this->limits->attemptVerify($user)) {
            return $this->fail($user, null, FailureReason::RateLimited, [
                'stage' => 'recovery', 'retry_after' => $this->limits->verifyAvailableIn($user),
            ]);
        }

        if (! $this->recoveryCodes->consume($user, $code)) {
            return $this->fail($user, null, FailureReason::InvalidRecoveryCode, ['stage' => 'recovery']);
        }

        $this->limits->clearVerify($user);

        $remaining = $this->recoveryCodes->remaining($user);
        $this->events->dispatch(new RecoveryCodeUsed($user, null, null, ['remaining' => $remaining]));

        return VerificationResult::success(['remaining' => $remaining]);
    }

    public function findFactor(MultiFactorAuthenticatable $user, int|string $factorId, bool $confirmed = true): ?MfaFactor
    {
        /** @var MfaFactor|null $factor */
        $factor = $user->mfaFactors()
            ->whereKey($factorId)
            ->when(
                $confirmed,
                fn ($q) => $q->whereNotNull('confirmed_at'),
                fn ($q) => $q->whereNull('confirmed_at')->where('created_at', '>', now()->subMinutes(PendingEnrollments::TTL_MINUTES)),
            )
            ->first();

        if ($factor === null || ! $this->mfa->isTypeEnabled($factor->type)) {
            return null;
        }

        return $factor;
    }

    /** @param array<string, mixed> $context */
    private function fail(MultiFactorAuthenticatable $user, ?FactorType $type, FailureReason $reason, array $context = []): VerificationResult
    {
        $this->events->dispatch(new VerificationFailed($user, $type, $reason, $context));

        return VerificationResult::failure($reason, $context);
    }
}
