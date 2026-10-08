<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Sms\Aws\SignatureV4;
use StrontiumCorp\LaravelMfa\Sms\Concerns\CallsProviderApi;

/**
 * Amazon SNS (SMS) via the Publish action of the SNS Query API, signed with
 * SigV4. Works from any host — only an IAM access key and a region are
 * needed (no AWS hosting, no SDK).
 *
 * Grant the key only sns:Publish. Leaving the SNS SMS sandbox and the
 * origination identities (10DLC, toll-free, sender IDs) are set up in the
 * AWS console (AWS End User Messaging).
 */
final class SnsSmsSender implements SmsSender
{
    use CallsProviderApi;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Http $http,
        private readonly array $config,
    ) {}

    public function send(string $to, string $message): void
    {
        $region = (string) ($this->config['region'] ?? 'us-east-1');

        if (preg_match('/^[a-z0-9-]+$/', $region) !== 1) {
            throw DeliveryFailed::provider('sns', 'invalid region in config');
        }

        $host = "sns.{$region}.amazonaws.com";
        $body = http_build_query($this->parameters($to, $message), '', '&', PHP_QUERY_RFC3986);
        $contentType = 'application/x-www-form-urlencoded; charset=utf-8';

        $headers = SignatureV4::sign(
            host: $host,
            headers: ['Content-Type' => $contentType],
            body: $body,
            service: 'sns',
            region: $region,
            accessKey: (string) ($this->config['key'] ?? ''),
            secretKey: (string) ($this->config['secret'] ?? ''),
            sessionToken: $this->config['token'] ?? null,
            at: now(),
        );

        try {
            $response = $this->request()
                ->withHeaders($headers)
                ->withBody($body, $contentType)
                ->post("https://{$host}/");
        } catch (ConnectionException $e) {
            throw self::connectionFailed('sns', $e);
        }

        if ($response->failed() || ! str_contains($response->body(), '<MessageId>')) {
            preg_match('#<Code>([^<]+)</Code>#', $response->body(), $error);

            throw DeliveryFailed::provider('sns', sprintf('HTTP %d, code %s', $response->status(), $error[1] ?? 'unknown'));
        }
    }

    /** @return array<string, string> */
    private function parameters(string $to, string $message): array
    {
        $params = ['Action' => 'Publish', 'Version' => '2010-03-31', 'PhoneNumber' => $to, 'Message' => $message];

        $attributes = array_filter([
            // One-time codes are transactional: routed for reliability, not price.
            'AWS.SNS.SMS.SMSType' => (string) ($this->config['sms_type'] ?? 'Transactional'),
            'AWS.SNS.SMS.SenderID' => $this->config['sender_id'] ?? null,
            'AWS.MM.SMS.OriginationNumber' => $this->config['origination_number'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $i = 1;
        foreach ($attributes as $name => $value) {
            $params["MessageAttributes.entry.{$i}.Name"] = $name;
            $params["MessageAttributes.entry.{$i}.Value.DataType"] = 'String';
            $params["MessageAttributes.entry.{$i}.Value.StringValue"] = (string) $value;
            $i++;
        }

        return $params;
    }
}
