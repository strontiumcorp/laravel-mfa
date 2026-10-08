<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Sms\Concerns\CallsProviderApi;

/**
 * Twilio Messages API over Laravel's HTTP client — no SDK required, and
 * fully testable with Http::fake().
 */
final class TwilioSmsSender implements SmsSender
{
    use CallsProviderApi;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Http $http,
        private readonly array $config,
    ) {}

    public function send(string $to, string $message): void
    {
        $sid = (string) ($this->config['sid'] ?? '');

        $payload = array_filter([
            'To' => $to,
            'Body' => $message,
            'MessagingServiceSid' => $this->config['messaging_service_sid'] ?? null,
            'From' => empty($this->config['messaging_service_sid']) ? ($this->config['from'] ?? null) : null,
        ]);

        try {
            $response = $this->request()
                ->asForm()
                ->withBasicAuth($sid, (string) ($this->config['token'] ?? ''))
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload);
        } catch (ConnectionException $e) {
            throw self::connectionFailed('twilio', $e);
        }

        if ($response->failed()) {
            throw DeliveryFailed::provider('twilio', sprintf('HTTP %d, code %s', $response->status(), (string) $response->json('code', 'unknown')));
        }
    }
}
