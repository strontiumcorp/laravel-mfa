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

    /** Sends more than this far apart start a new cooldown streak. */
    public const STREAK_WINDOW_SECONDS = 3600;

    /**
     * Issue a new code, subject to the exponential resend cooldown.
     *
     * The cooldown is decided first, under the factor lock. Only when a code
     * will really be issued is $gate called (rate limits / caps), so requests
     * rejected by the cooldown never consume any quota.
     *
     * @param  array{length: int, ttl: int, resend_cooldown: int|array<string, int|float>}  $options
     * @param  (Closure(): (VerificationResult|null))|null  $gate  failure = refuse, null = allow
     * @return array{code: string|null, result: VerificationResult}
     */
    public function issue(MfaFactor $factor, array $options, ?Closure $gate = null): array
    {
        return DB::transaction(function () use ($factor, $options, $gate): array {
            $lastVerified = $this->lock($factor);

            // Streak: sends since the later of "an hour ago" and the last
            // successful verification. Both reset the curve.
            $since = now()->subSeconds(self::STREAK_WINDOW_SECONDS);
            if ($lastVerified !== null && $lastVerified->gt($since)) {
                $since = $lastVerified;
            }

            $streak = $factor->otpCodes()->where('created_at', '>', $since)->count();

            /** @var MfaOtpCode|null $latest */
            $latest = $factor->otpCodes()->latest('id')->first();

            // A resend is always allowed once the current code is unusable
            // (expired or burned), otherwise the curve applies.
            $latestUsable = $latest !== null && $latest->consumed_at === null && $latest->expires_at->isFuture();

            if ($latestUsable && $latest->created_at !== null) {
                $readyAt = $latest->created_at->copy()->addSeconds(Cooldown::after($streak, $options['resend_cooldown']));

                if ($readyAt->isFuture()) {
                    return ['code' => null, 'result' => VerificationResult::failure(FailureReason::Cooldown, [
                        // Whole seconds, rounded up: $readyAt is whole seconds (from the
                        // DB), so this is ceil() of the exact wait. Not diffInSeconds():
                        // Carbon 2 truncates it (89 instead of 90).
                        'retry_after' => $readyAt->getTimestamp() - now()->getTimestamp(),
                    ])];
                }
            }

            if ($gate !== null && ($refused = $gate()) !== null) {
                return ['code' => null, 'result' => $refused];
            }

            $factor->otpCodes()->whereNull('consumed_at')->update(['consumed_at' => now()]);

            $code = $this->generator->otp($options['length']);

            $otp = $factor->otpCodes()->create([
                'code_hash' => $this->hasher->hash($code, $this->scope($factor)),
                'expires_at' => now()->addSeconds($options['ttl']),
            ]);

            return ['code' => $code, 'result' => VerificationResult::success([
                'otp_id' => $otp->id,
                'streak' => $streak + 1,
                // When the next resend unlocks: the curve, or code expiry if sooner.
                // Equivalent mutant: ttl is an int in config.
                'retry_after' => min(Cooldown::after($streak + 1, $options['resend_cooldown']), (int) $options['ttl']), // @pest-mutate-ignore: RemoveIntegerCast
            ])];
        });
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
