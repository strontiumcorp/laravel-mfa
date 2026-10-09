<?php

namespace StrontiumCorp\LaravelMfa\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\ChallengeDeliveryFailed;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Notifications\OtpCodeNotification;
use StrontiumCorp\LaravelMfa\Support\OtpStore;
use Throwable;

/**
 * Delivers a one-time code. Runs synchronously by default; when queued the
 * payload is encrypted at rest (it contains the plain code).
 */
class DeliverOtp implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    /** Set by the dispatcher when running inline (no queue connection). */
    public bool $synchronous = false;

    /** @var list<int> */
    public array $backoff;

    public function __construct(
        public readonly int $factorId,
        #[\SensitiveParameter] public readonly string $code,
        public readonly int $ttlSeconds,
        public readonly ?int $otpId = null,
    ) {
        $this->tries = (int) config('mfa.delivery.tries');
        $this->backoff = array_map('intval', (array) config('mfa.delivery.backoff'));
    }

    public function handle(MetricsRecorder $metrics): void
    {
        $factor = MfaFactor::query()->find($this->factorId);

        if ($factor === null || $factor->destination === null) {
            return; // Factor removed before delivery — nothing to do.
        }

        $started = hrtime(true);

        try {
            match ($factor->type) {
                FactorType::Email => $this->sendEmail($factor),
                // Resolved here, not injected: a broken SMS config must not
                // break email delivery, and must surface as DeliveryFailed.
                FactorType::Sms => app(SmsSender::class)->send($factor->destination, $this->smsMessage()),
                FactorType::Totp => null,
            };
        } catch (DeliveryFailed $e) {
            // It may have arrived: a retry would send it twice. Fail now
            // (failed() runs once, the code stays valid). Inline, the caller
            // (OtpFactor) handles it.
            if ($e->maybeDelivered && ! $this->synchronous && $this->job !== null) {
                $this->fail($e);

                return;
            }

            throw $e;
        } catch (Throwable $e) {
            // Transport messages can contain the recipient (e.g. SMTP "550
            // for <jane@…>"). Keep only the class in what reaches the MFA log
            // and audit table; the original stays chained for the app's own
            // exception reporting.
            throw new DeliveryFailed('['.$factor->type->value.'] '.$e::class, previous: $e);
        } finally {
            $metrics->timing('mfa.delivery_duration_ms', (hrtime(true) - $started) / 1e6, ['factor' => $factor->type->value]);
        }
    }

    /** Called by the queue worker once retries are exhausted. */
    public function failed(?Throwable $exception): void
    {
        // dispatchSync() runs on the sync queue, which also invokes failed();
        // the synchronous caller (OtpFactor) already reports that failure.
        // A serialized flag (not $this->job) because Laravel 11's sync queue
        // calls failed() on a freshly unserialized copy of the job.
        if ($this->synchronous) {
            return;
        }

        $maybeDelivered = $exception instanceof DeliveryFailed && $exception->maybeDelivered;

        // The code never arrived: drop it so the resend cooldown doesn't make
        // the user wait for it. One that may have arrived stays valid.
        if ($this->otpId !== null && ! $maybeDelivered) {
            app(OtpStore::class)->discard($this->otpId);
        }

        $factor = MfaFactor::query()->find($this->factorId);

        app(Events::class)->dispatch(new ChallengeDeliveryFailed(
            $factor?->user, $factor?->type, FailureReason::DeliveryFailed,
            ['factor_id' => $this->factorId, 'error' => $exception?->getMessage(), 'queued' => true, ...($maybeDelivered ? ['maybe_delivered' => true] : [])],
        ));
    }

    private function sendEmail(MfaFactor $factor): void
    {
        /** @var class-string<OtpCodeNotification> $notification */
        $notification = config('mfa.factors.email.notification');

        Notification::route('mail', $factor->destination)->notifyNow(new $notification($this->code, $this->ttlSeconds));
    }

    private function smsMessage(): string
    {
        return strtr((string) config('mfa.factors.sms.message'), [
            ':code' => $this->code,
            ':app' => (string) config('app.name'),
            ':minutes' => (string) max(1, intdiv($this->ttlSeconds, 60)),
        ]);
    }
}
