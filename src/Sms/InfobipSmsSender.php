<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;

/**
 * Infobip SMS API v3 (POST /sms/3/messages) over Laravel's HTTP client.
 * Use your account's personal base URL (shown in the Infobip portal) for
 * the lowest latency; api.infobip.com also works.
 */
final class InfobipSmsSender implements SmsSender
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Http $http,
        private readonly array $config,
    ) {}

    public function send(string $to, string $message): void
    {
        $base = rtrim((string) ($this->config['base_url'] ?? 'https://api.infobip.com'), '/');

        try {
            $response = $this->http
                ->withHeaders(['Authorization' => 'App '.($this->config['api_key'] ?? ''), 'Accept' => 'application/json'])
                ->asJson()
                ->timeout(10)
                ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post("{$base}/sms/3/messages", [
                    'messages' => [[
                        'sender' => (string) ($this->config['from'] ?? ''),
                        'destinations' => [['to' => ltrim($to, '+')]],
                        'content' => ['text' => $message],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            throw DeliveryFailed::provider('infobip', 'connection: '.$e->getMessage());
        }

        if ($response->failed()) {
            $code = $response->json('requestError.serviceException.messageId') ?? $response->json('errorCode') ?? 'unknown';

            throw DeliveryFailed::provider('infobip', sprintf('HTTP %d, code %s', $response->status(), (string) $code));
        }

        // Allow-list: anything but accepted/delivered (REJECTED, UNDELIVERABLE,
        // an unexpected shape…) is a failure, so failover can take over.
        $group = (string) $response->json('messages.0.status.groupName', 'unknown');

        if (! in_array($group, ['PENDING', 'DELIVERED'], true)) {
            throw DeliveryFailed::provider('infobip', 'status '.$response->json('messages.0.status.name', $group));
        }
    }
}
