<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Events\Dispatcher;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Events\FactorEnabled;
use StrontiumCorp\LaravelMfa\Events\FactorEnrollmentStarted;
use StrontiumCorp\LaravelMfa\Events\RecoveryCodesGenerated;
use StrontiumCorp\LaravelMfa\Exceptions\EnrollmentFailed;
use StrontiumCorp\LaravelMfa\Factors\TotpFactor;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;

final class EnrollmentService
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly ChallengeService $challenges,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{factor: MfaFactor, setup: array<string, mixed>}
     *
     * @throws EnrollmentFailed
     */
    public function start(MultiFactorAuthenticatable $user, FactorType $type, array $input = []): array
    {
        if (! $this->mfa->isTypeEnabled($type)) {
            throw new EnrollmentFailed(FailureReason::FactorDisabled);
        }

        $enrollment = $this->mfa->factor($type)->enroll($user, $input);

        $this->events->dispatch(new FactorEnrollmentStarted($user, $type, null, ['factor_id' => $enrollment['factor']->getKey()]));

        return $enrollment;
    }

    /**
     * @return array{result: VerificationResult, recovery_codes: list<string>|null}
     */
    public function confirm(MultiFactorAuthenticatable $user, int|string $factorId, string $code): array
    {
        $factor = $this->challenges->findFactor($user, $factorId, confirmed: false);

        if ($factor === null) {
            // Equivalent mutant: recovery_codes is only read on success.
            return ['result' => VerificationResult::failure(FailureReason::FactorNotFound), 'recovery_codes' => null]; // @pest-mutate-ignore: RemoveArrayItem
        }

        $result = $this->challenges->verifyFactor($user, $factor, $code, stage: 'enrollment');

        if ($result->failed()) {
            // Equivalent mutant: recovery_codes is only read on success.
            return ['result' => $result, 'recovery_codes' => null]; // @pest-mutate-ignore: RemoveArrayItem
        }

        $factor->forceFill(['confirmed_at' => now()])->save();

        $this->events->dispatch(new FactorEnabled($user, $factor->type, null, ['factor_id' => $factor->getKey()]));

        $codes = $this->recoveryCodes->remaining($user) === 0 ? $this->regenerateRecoveryCodes($user) : null;

        return ['result' => $result, 'recovery_codes' => $codes];
    }

    /** Resend the code for an unconfirmed OTP factor. */
    public function resend(MultiFactorAuthenticatable $user, int|string $factorId): VerificationResult
    {
        return $this->challenges->send($user, $factorId, confirmed: false);
    }

    public function disable(MultiFactorAuthenticatable $user, int|string $factorId): void
    {
        /** @var MfaFactor|null $factor */
        $factor = $user->mfaFactors()->whereKey($factorId)->first();

        if ($factor === null) {
            return;
        }

        $factor->delete();

        if (! $user->mfaFactors()->whereNotNull('confirmed_at')->exists()) {
            $this->recoveryCodes->clear($user);
        }

        $this->events->dispatch(new FactorDisabled($user, $factor->type, null, ['factor_id' => $factor->getKey()]));
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(MultiFactorAuthenticatable $user): array
    {
        $codes = $this->recoveryCodes->generate($user);

        $this->events->dispatch(new RecoveryCodesGenerated($user, null, null, ['count' => count($codes)]));

        return $codes;
    }

    /**
     * Data the settings page needs to finish a pending enrollment.
     *
     * @return array<string, mixed>|null
     */
    public function pendingSetup(MultiFactorAuthenticatable $user, MfaFactor $factor): ?array
    {
        if ($factor->isConfirmed()) {
            return null;
        }

        $setup = $factor->toPublicArray();

        $driver = $this->mfa->factor($factor->type);

        if ($driver instanceof TotpFactor) {
            $setup += $driver->setupData($factor, $user);
        }

        return $setup;
    }
}
