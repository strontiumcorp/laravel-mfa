<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
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
        private readonly DestinationFingerprint $destinations,
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

        $this->events->dispatch(new FactorEnrollmentStarted($user, $type, null, [
            'factor_id' => $enrollment['factor']->getKey(),
            ...$this->destinations->context($enrollment['factor'], $user),
        ]));

        return $enrollment;
    }

    /**
     * Check the code of an unconfirmed factor. When the account has no
     * unused recovery codes (its first method, or all used), a new set comes
     * back and the factor stays unconfirmed: enable() turns it on once the
     * user has saved them (PendingRecoveryCodes). Otherwise it is on now.
     *
     * @return array{result: VerificationResult, recovery_codes: list<string>|null, confirmed: bool}
     */
    public function confirm(MultiFactorAuthenticatable $user, int|string $factorId, string $code): array
    {
        $factor = $this->challenges->findFactor($user, $factorId, confirmed: false);

        if ($factor === null) {
            // Equivalent mutant: recovery_codes is only read on success.
            return ['result' => VerificationResult::failure(FailureReason::FactorNotFound), 'recovery_codes' => null, 'confirmed' => false]; // @pest-mutate-ignore: RemoveArrayItem
        }

        $result = $this->challenges->verifyFactor($user, $factor, $code, stage: 'enrollment');

        if ($result->failed()) {
            // Equivalent mutant: recovery_codes is only read on success.
            return ['result' => $result, 'recovery_codes' => null, 'confirmed' => false]; // @pest-mutate-ignore: RemoveArrayItem
        }

        if ($this->recoveryCodes->remaining($user) === 0) {
            return ['result' => $result, 'recovery_codes' => $this->recoveryCodes->make(), 'confirmed' => false];
        }

        $this->enable($user, $factor);

        return ['result' => $result, 'recovery_codes' => null, 'confirmed' => true];
    }

    /**
     * An unconfirmed factor of the user whose code was verified, whatever its
     * age (its setup window is the pending codes' to enforce).
     */
    public function verifiedFactor(MultiFactorAuthenticatable $user, int|string $factorId): ?MfaFactor
    {
        /** @var MfaFactor|null */
        return $user->mfaFactors()->whereKey($factorId)->whereNull('confirmed_at')->first();
    }

    /**
     * Turn a verified factor on, with the recovery codes the user saved for
     * it (a first method), in one transaction.
     *
     * @param  list<string>|null  $codes
     */
    public function enable(MultiFactorAuthenticatable $user, MfaFactor $factor, ?array $codes = null): void
    {
        // "initial": the set that comes with the account's first method (its
        // FactorEnabled email says so), not a new set after all were used.
        $first = ! $user->mfaFactors()->whereNotNull('confirmed_at')->whereKeyNot($factor->getKey())->exists();

        DB::transaction(function () use ($user, $factor, $codes): void {
            $factor->forceFill(['confirmed_at' => now()])->save();

            if ($codes !== null) {
                $this->recoveryCodes->store($user, $codes);
            }
        });

        $this->events->dispatch(new FactorEnabled($user, $factor->type, null, [
            'factor_id' => $factor->getKey(),
            ...$this->destinations->context($factor, $user),
        ]));

        if ($codes !== null) {
            $this->recoveryCodesSaved($user, $codes, initial: $first);
        }
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

    /**
     * A new set for an account that has codes: not stored until saved
     * (PendingRecoveryCodes), so the old ones keep working until then.
     *
     * @return list<string>
     */
    public function newRecoveryCodes(): array
    {
        return $this->recoveryCodes->make();
    }

    /**
     * Replace the user's recovery codes with a set they saved.
     *
     * @param  list<string>  $codes
     */
    public function saveRecoveryCodes(MultiFactorAuthenticatable $user, array $codes): void
    {
        $this->recoveryCodes->store($user, $codes);
        $this->recoveryCodesSaved($user, $codes);
    }

    /**
     * @param  list<string>  $codes
     * @param  bool  $initial  the set that comes with the account's first factor (context "initial")
     */
    private function recoveryCodesSaved(MultiFactorAuthenticatable $user, array $codes, bool $initial = false): void
    {
        $this->events->dispatch(new RecoveryCodesGenerated($user, null, null, ['count' => count($codes), ...($initial ? ['initial' => true] : [])]));
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
