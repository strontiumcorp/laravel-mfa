<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\SmsProviderFailed;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use Throwable;

/**
 * Tries each provider in order until one accepts the message. Every failed
 * attempt emits SmsProviderFailed (logged, audited and counted like every
 * MFA event, tagged with the provider). If all fail, DeliveryFailed is
 * thrown and the usual ChallengeDeliveryFailed flow takes over.
 */
final class FailoverSmsSender implements SmsSender
{
    /** @param array<string, SmsSender> $senders provider name => sender, in order */
    public function __construct(
        private readonly array $senders,
        private readonly Dispatcher $events,
    ) {
        if ($senders === []) {
            throw new InvalidArgumentException('The failover SMS driver needs at least one driver in [drivers].');
        }
    }

    public function send(string $to, string $message): void
    {
        $names = array_keys($this->senders);
        $failures = [];

        foreach ($names as $i => $name) {
            try {
                $this->senders[$name]->send($to, $message);

                return;
            } catch (Throwable $e) {
                // DeliveryFailed messages are PII-free by contract; anything
                // else (e.g. a bug in a custom driver) is reported in full to
                // the app's error handler and summarised by class here.
                if (! $e instanceof DeliveryFailed) {
                    report($e);
                }

                $error = $e instanceof DeliveryFailed ? $e->getMessage() : $e::class;
                $failures[] = "{$name}: {$error}";

                $this->events->dispatch(new SmsProviderFailed(null, FactorType::Sms, FailureReason::DeliveryFailed, [
                    'provider' => $name,
                    'next' => $names[$i + 1] ?? null,
                    'error' => $error,
                ]));
            }
        }

        throw DeliveryFailed::provider('failover', 'all providers failed — '.implode('; ', $failures));
    }
}
