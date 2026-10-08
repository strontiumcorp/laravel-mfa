<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;

/**
 * Vonage (Nexmo) SMS API over Laravel's HTTP client.
 */
final class VonageSmsSender implements SmsSender
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Http $http,
        private readonly array $config,
    ) {}

    public function send(string $to, string $message): void
    {
        try {
            $response = $this->http
                ->asForm()
                ->timeout(10)
                ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post('https://rest.nexmo.com/sms/json', [
                    'api_key' => $this->config['key'] ?? '',
                    'api_secret' => $this->config['secret'] ?? '',
                    'from' => $this->config['from'] ?? '',
                    'to' => ltrim($to, '+'),
                    'text' => $message,
                ]);
        } catch (ConnectionException $e) {
            throw DeliveryFailed::provider('vonage', 'connection: '.$e->getMessage());
        }

        $status = (string) $response->json('messages.0.status', 'unknown');

        if ($response->failed() || $status !== '0') {
            throw DeliveryFailed::provider('vonage', sprintf('HTTP %d, status %s', $response->status(), $status));
        }
    }
}
