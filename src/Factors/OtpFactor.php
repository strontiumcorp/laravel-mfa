<?php

namespace StrontiumCorp\LaravelMfa\Factors;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Facades\Context;
use StrontiumCorp\LaravelMfa\Contracts\Factor;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\ChallengeDeliveryFailed;
use StrontiumCorp\LaravelMfa\Events\ChallengeSent;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Exceptions\EnrollmentFailed;
use StrontiumCorp\LaravelMfa\Jobs\DeliverOtp;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\ChallengeState;
use StrontiumCorp\LaravelMfa\Support\Mask;
use StrontiumCorp\LaravelMfa\Support\OtpStore;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use StrontiumCorp\LaravelMfa\Support\SendGuard;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;

/**
 * Shared behaviour for factors that deliver a code (email, SMS).
 */
abstract class OtpFactor implements Factor
{
    /**
     * @param  array{length: int, ttl: int, max_attempts: int, resend_cooldown: int}  $config
     * @param  array{queue_connection?: string|null, queue?: string|null}  $delivery
     */
    public function __construct(
        protected readonly OtpStore $store,
        protected readonly SendGuard $guard,
        protected readonly Dispatcher $bus,
        protected readonly Events $events,
        protected readonly array $config,
        protected readonly array $delivery,
    ) {}

    /**
     * Validate and normalise the destination for a new factor.
     *
     * @param  array<string, mixed>  $input
     */
    abstract protected function resolveDestination(MultiFactorAuthenticatable $user, array $input): string|FailureReason;

    public function enroll(MultiFactorAuthenticatable $user, array $input): array
    {
        $destination = $this->resolveDestination($user, $input);

        if ($destination instanceof FailureReason) {
            throw new EnrollmentFailed($destination);
        }

        $user->mfaFactors()->where('type', $this->type())->whereNull('confirmed_at')->delete();

        /** @var MfaFactor $factor */
        $factor = $user->mfaFactors()->create([
            'type' => $this->type(),
            'label' => $input['label'] ?? $this->type()->label(),
            'destination' => $destination,
        ]);

        $sent = $this->challenge($factor);

        // Refused by a send limit: don't leave a pending factor behind.
        if ($sent->reason?->isLimit()) {
            $factor->delete();

            throw new EnrollmentFailed($sent->reason, $sent->context);
        }

        return ['factor' => $factor, 'setup' => [
            'destination' => Mask::destination($this->type(), $destination),
            'sent' => $sent->successful,
            'reason' => $sent->reason?->value,
            'retry_after' => $sent->context['retry_after'] ?? null,
        ]];
    }

    /**
     * Re-validated at every send, so config changes (e.g. a newly blocked
     * prefix) also apply to factors enrolled earlier.
     */
    protected function destinationAllowed(string $destination): bool
    {
        return true;
    }

    public function challenge(MfaFactor $factor): VerificationResult
    {
        if ($factor->destination === null || ! $this->destinationAllowed($factor->destination)) {
            return VerificationResult::failure(FailureReason::DestinationNotAllowed);
        }

        // Every send path (login challenge, resend, enrollment) comes through
        // here. The cooldown is checked first; the send limits only run (and
        // only count) when a code is really going out.
        $issued = $this->store->issue(
            $factor,
            $this->config,
            fn () => $this->guard->attempt($factor, Context::getHidden(RequestContext::IP)),
        );

        if ($issued['code'] === null) {
            return $issued['result'];
        }

        // Equivalent mutant(s): ttl is an int in config.
        $job = new DeliverOtp($factor->getKey(), $issued['code'], (int) $this->config['ttl'], (int) $issued['result']->context['otp_id']); // @pest-mutate-ignore: RemoveIntegerCast
        $queued = $this->queued();

        if (! $queued) {
            try {
                $job->synchronous = true;
                $this->bus->dispatchSync($job);
            } catch (DeliveryFailed $e) {
                report($e); // full detail (with the chained cause) to the app's error log

                $this->events->dispatch(new ChallengeDeliveryFailed(
                    $factor->user, $this->type(), FailureReason::DeliveryFailed,
                    ['factor_id' => $factor->getKey(), 'error' => $e->getMessage(), ...($e->maybeDelivered ? ['maybe_delivered' => true] : [])],
                ));

                // It may have arrived (e.g. a timeout after the provider got
                // it): keep the code and answer as sent, with the cooldown, so
                // the user enters it or resends later. Never sent: drop it,
                // so a resend isn't held up by the cooldown.
                if ($e->maybeDelivered) {
                    return VerificationResult::success(['retry_after' => $issued['result']->context['retry_after']]);
                }

                // Equivalent mutant(s): otp_id is an int primary key.
                $this->store->discard((int) $issued['result']->context['otp_id']); // @pest-mutate-ignore: RemoveIntegerCast

                return VerificationResult::failure(FailureReason::DeliveryFailed);
            }
        } else {
            $this->bus->dispatch(
                $job->onConnection($this->delivery['queue_connection'] ?: null)->onQueue($this->delivery['queue'] ?: null)
            );
        }

        $this->events->dispatch(new ChallengeSent($factor->user, $this->type(), null, [
            'factor_id' => $factor->getKey(),
            'queued' => $queued,
        ]));

        // Equivalent mutant(s): unverified_sends is computed as an int.
        $streak = (int) $issued['result']->context['unverified_sends']; // @pest-mutate-ignore: RemoveIntegerCast
        // Equivalent mutant(s): config ints, or numeric env strings that PHP compares numerically.
        $warnAfter = (int) config('mfa.rate_limit.warn_after_unverified_sends'); // @pest-mutate-ignore: RemoveIntegerCast

        if ($factor->isConfirmed() && $warnAfter > 0 && $streak >= $warnAfter) {
            $this->guard->warnOnce($factor, 'repeated_unverified_sends', ['sends' => $streak]);
        }

        return VerificationResult::success(['retry_after' => $issued['result']->context['retry_after']]);
    }

    /**
     * The code already out, its resend cooldown and the code length
     * (factors.{type}.length). Read-only, for the challenge page.
     */
    public function challengeState(MfaFactor $factor): ChallengeState
    {
        return $this->store->status($factor, $this->config);
    }

    /**
     * Queued when a connection or a queue name is set (a queue name alone
     * uses the default connection), unless that connection is "sync": then
     * the code is sent inline, with immediate error feedback.
     */
    private function queued(): bool
    {
        $connection = ($this->delivery['queue_connection'] ?? null) ?: null;

        if ($connection === null && empty($this->delivery['queue'])) {
            return false;
        }

        $connection ??= config('queue.default');

        return config("queue.connections.{$connection}.driver") !== 'sync';
    }

    public function verify(MfaFactor $factor, string $code): VerificationResult
    {
        // Equivalent mutant(s): preg_replace only returns null on a regex error.
        $code = preg_replace('/\s+/', '', $code) ?? ''; // @pest-mutate-ignore: EmptyStringToNotEmpty

        if (preg_match('/^\d{4,10}$/', $code) !== 1) {
            return VerificationResult::failure(FailureReason::InvalidCode);
        }

        return $this->store->verify($factor, $code, $this->config);
    }
}
