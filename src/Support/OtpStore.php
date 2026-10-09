<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaOtpCode;

/**
 * Issues and verifies delivered one-time codes (email / SMS).
 *
 * Every read-modify-write runs in a transaction holding a row lock on the
 * factor, so concurrent requests across many app servers cannot double-spend
 * a code or bypass the attempt limit.
 */
final class OtpStore
{
    public function __construct(
        private readonly CodeGenerator $generator,
        private readonly CodeHasher $hasher,
    ) {}

    /** The cooldown curve counts the sends of the last hour. */
    public const STREAK_WINDOW_SECONDS = 3600;

    /**
     * Issue a new code, subject to the exponential resend cooldown.
     *
     * The cooldown is decided first, under the factor lock. Only when a code
     * will really be issued is $gate called (rate limits / caps), so requests
     * rejected by the cooldown never consume any quota.
     *
     * @param  array{length: int, ttl: int, max_attempts: int, resend_cooldown: int|array<string, int|float>}  $options
     * @param  (Closure(): (VerificationResult|null))|null  $gate  failure = refuse, null = allow
     * @return array{code: string|null, result: VerificationResult}
     */
    public function issue(MfaFactor $factor, array $options, ?Closure $gate = null): array
    {
        return DB::transaction(function () use ($factor, $options, $gate): array {
            $lastVerified = $this->lock($factor);
            $cooldown = $this->cooldown($factor, $options);

            if ($cooldown['ready_at'] !== null && $cooldown['ready_at']->isFuture()) {
                return ['code' => null, 'result' => VerificationResult::failure(FailureReason::Cooldown, [
                    // Whole seconds, rounded up: ready_at is whole seconds (from the
                    // DB), so this is ceil() of the exact wait. Not diffInSeconds():
                    // Carbon 2 truncates it (89 instead of 90).
                    'retry_after' => $cooldown['ready_at']->getTimestamp() - now()->getTimestamp(),
                ])];
            }

            if ($gate !== null && ($refused = $gate()) !== null) {
                return ['code' => null, 'result' => $refused];
            }

            // Counted before the new code exists, like the streak.
            $unverified = $this->unverifiedSends($factor, $lastVerified, $cooldown['streak']);

            // Supersede the code out: it stops being valid now. Its expiry is
            // set a second before, so it reads as expired, never as a code
            // used to verify (MfaOtpCode::wasVerified()), even if the new code
            // is discarded after a failed delivery and this one is the latest
            // again. Otherwise a resend fired in the second the code expired
            // would leave a cooldown behind a delivery that never happened.
            $factor->otpCodes()->whereNull('consumed_at')->update(['consumed_at' => now(), 'expires_at' => now()->subSecond()]);

            $code = $this->generator->otp($options['length']);

            $otp = $factor->otpCodes()->create([
                'code_hash' => $this->hasher->hash($code, $this->scope($factor)),
                'expires_at' => now()->addSeconds($options['ttl']),
            ]);

            return ['code' => $code, 'result' => VerificationResult::success([
                'otp_id' => $otp->id,
                'streak' => $cooldown['streak'] + 1,
                // Sends since the last successful verification (for the
                // "codes keep being requested" warning), this one included.
                'unverified_sends' => $unverified + 1,
                // When the next resend unlocks: the curve, or code expiry if sooner.
                // Equivalent mutant: ttl is an int in config.
                'retry_after' => min(Cooldown::after($cooldown['streak'] + 1, $options['resend_cooldown']), (int) $options['ttl']), // @pest-mutate-ignore: RemoveIntegerCast
            ])];
        });
    }

    /**
     * Read-only view of the resend cooldown, for the challenge page: whether
     * a usable code is out, the whole seconds until a resend is allowed, and
     * until that code expires. After a successful verification no code is
     * out, but the next send may still have to wait.
     * Same maths as issue(), but no lock and no writes.
     *
     * @param  array{length: int, max_attempts: int, resend_cooldown: int|array<string, int|float>}  $options
     */
    public function status(MfaFactor $factor, array $options): ChallengeState
    {
        $cooldown = $this->cooldown($factor, $options);
        // Whole seconds from timestamps, not diffInSeconds() (Carbon 2 truncates).
        $now = now()->getTimestamp();
        $retryAfter = $cooldown['ready_at'] === null ? null : $cooldown['ready_at']->getTimestamp() - $now;

        if ($cooldown['usable'] === null) {
            return ChallengeState::none($options['length'], $retryAfter);
        }

        return ChallengeState::sent($retryAfter, $cooldown['usable']->expires_at->getTimestamp() - $now, $options['length']);
    }

    /**
     * The resend cooldown as issue() applies it.
     *
     * Streak: every code sent for the factor in the last hour, whether or not
     * it was verified, so logging in, verifying and logging out again can't
     * restart the curve. ready_at is when the next send is allowed (null =
     * now):
     *  - a usable code is out: when the curve allows a resend, or when the
     *    code expires if that is sooner (it is unusable from then on);
     *  - the latest code was used by a successful verification: one step
     *    lower on the curve, from that code's send (the first re-login after
     *    a single send is free: Cooldown::after(0) is 0);
     *  - the latest code expired or was burned by wrong guesses: now.
     * issue()'s refusal and status() both read it, so they always agree.
     *
     * @param  array{max_attempts: int, resend_cooldown: int|array<string, int|float>}  $options
     * @return array{streak: int, usable: MfaOtpCode|null, ready_at: Carbon|null}
     */
    private function cooldown(MfaFactor $factor, array $options): array
    {
        $streak = $factor->otpCodes()->where('created_at', '>', now()->subSeconds(self::STREAK_WINDOW_SECONDS))->count();

        /** @var MfaOtpCode|null $latest */
        $latest = $factor->otpCodes()->latest('id')->first();

        if ($latest !== null && $latest->wasVerified($options['max_attempts'])) {
            $readyAt = $latest->created_at?->copy()->addSeconds(Cooldown::after($streak - 1, $options['resend_cooldown']));

            return ['streak' => $streak, 'usable' => null, 'ready_at' => $readyAt];
        }

        if ($latest === null || $latest->consumed_at !== null || ! $latest->expires_at->isFuture()) {
            return ['streak' => $streak, 'usable' => null, 'ready_at' => null];
        }

        $readyAt = $latest->created_at?->copy()->addSeconds(Cooldown::after($streak, $options['resend_cooldown']));

        return [
            'streak' => $streak,
            'usable' => $latest,
            'ready_at' => $readyAt !== null && $readyAt->gt($latest->expires_at) ? Carbon::instance($latest->expires_at) : $readyAt,
        ];
    }

    /**
     * Sends in the last hour since the factor's last successful verification
     * ($streak when it wasn't verified in that hour).
     */
    private function unverifiedSends(MfaFactor $factor, ?Carbon $lastVerified, int $streak): int
    {
        if ($lastVerified === null || $lastVerified->lte(now()->subSeconds(self::STREAK_WINDOW_SECONDS))) {
            return $streak;
        }

        return $factor->otpCodes()->where('created_at', '>', $lastVerified)->count();
    }

    /** @param array{max_attempts: int} $options */
    public function verify(MfaFactor $factor, string $code, array $options): VerificationResult
    {
        return DB::transaction(function () use ($factor, $code, $options): VerificationResult {
            // Equivalent in tests: the row lock serialises concurrent verifies
            // across servers, which a single-process SQLite run can't observe.
            $this->lock($factor); // @pest-mutate-ignore: RemoveMethodCall

            /** @var MfaOtpCode|null $otp */
            $otp = $factor->otpCodes()->whereNull('consumed_at')->latest('id')->first();

            if ($otp === null) {
                return VerificationResult::failure(FailureReason::NoActiveCode);
            }

            if ($otp->expires_at->isPast()) {
                $otp->update(['consumed_at' => now()]);

                return VerificationResult::failure(FailureReason::Expired);
            }

            if ($this->hasher->matches($code, $otp->code_hash, $this->scope($factor))) {
                $otp->update(['consumed_at' => now()]);

                return VerificationResult::success();
            }

            $attempts = $otp->attempts + 1;
            $burned = $attempts >= $options['max_attempts'];

            $otp->update(['attempts' => $attempts, 'consumed_at' => $burned ? now() : null]);

            return VerificationResult::failure(
                $burned ? FailureReason::TooManyAttempts : FailureReason::InvalidCode,
                // Equivalent mutant(s): attempts never exceed max_attempts (the code is burned at max), so the floor is never reached.
                ['attempts_remaining' => max($options['max_attempts'] - $attempts, 0)], // @pest-mutate-ignore: DecrementInteger
            );
        });
    }

    /** Remove a code whose delivery failed so the user can retry immediately. */
    public function discard(int $otpId): void
    {
        MfaOtpCode::query()->whereKey($otpId)->delete();
    }

    /** Lock the factor row; returns its fresh last_used_at. */
    private function lock(MfaFactor $factor): ?Carbon
    {
        $lastUsed = MfaFactor::query()->whereKey($factor->getKey())->lockForUpdate()->value('last_used_at');

        return $lastUsed === null ? null : Carbon::parse($lastUsed);
    }

    private function scope(MfaFactor $factor): string
    {
        // Equivalent mutants: the scope is defence in depth — codes are already
        // stored and looked up per factor row, so any stable scope works.
        return 'otp:'.$factor->getKey(); // @pest-mutate-ignore: ConcatRemoveLeft,ConcatRemoveRight,ConcatSwitchSides
    }
}
