<?php

namespace StrontiumCorp\LaravelMfa\Testing;

use PHPUnit\Framework\Assert;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;

/**
 * In-memory SMS transport for tests: Mfa::fakeSms().
 */
final class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    private ?string $failWith = null;

    public function send(string $to, string $message): void
    {
        if ($this->failWith !== null) {
            throw new DeliveryFailed($this->failWith);
        }

        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /** Make subsequent sends fail, to test delivery-failure handling. */
    public function failWith(string $message = 'Simulated provider outage'): self
    {
        $this->failWith = $message;

        return $this;
    }

    public function assertSentTo(string $to, int $times = 1): void
    {
        $count = count(array_filter($this->sent, fn ($sms) => $sms['to'] === $to));

        Assert::assertSame($times, $count, "Expected {$times} SMS to [{$to}], got {$count}.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Expected no SMS to be sent.');
    }

    public function lastCodeFor(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['to'] === $to && preg_match('/\b(\d{4,10})\b/', $sms['message'], $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
