<?php

namespace StrontiumCorp\LaravelMfa\Sms\Aws;

use DateTimeInterface;

/**
 * AWS Signature Version 4 for simple POST requests to an AWS service root
 * ("/", no query string), which is all the SNS Query API needs.
 *
 * Implemented here so AWS works from any host (self-hosted, other clouds)
 * without pulling in the full AWS SDK. Verified against signatures produced
 * by the official aws/aws-sdk-php (see tests/Unit/AwsSignatureV4Test.php).
 */
final class SignatureV4
{
    /**
     * Headers to add to the request: X-Amz-Date, Authorization and, for
     * temporary credentials, X-Amz-Security-Token.
     *
     * @param  array<string, string>  $headers  must include Content-Type
     * @return array<string, string>
     */
    public static function sign(
        string $host,
        array $headers,
        string $body,
        string $service,
        string $region,
        string $accessKey,
        #[\SensitiveParameter] string $secretKey,
        #[\SensitiveParameter] ?string $sessionToken,
        DateTimeInterface $at,
    ): array {
        $amzDate = gmdate('Ymd\THis\Z', $at->getTimestamp());
        $date = substr($amzDate, 0, 8);

        $added = ['X-Amz-Date' => $amzDate];
        if ($sessionToken !== null && $sessionToken !== '') {
            $added['X-Amz-Security-Token'] = $sessionToken;
        }

        $canonical = ['host' => $host];
        foreach ([...$headers, ...$added] as $name => $value) {
            $canonical[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        ksort($canonical);

        $signedHeaders = implode(';', array_keys($canonical));
        $canonicalHeaders = implode('', array_map(fn ($name, $value) => "{$name}:{$value}\n", array_keys($canonical), $canonical));

        $canonicalRequest = implode("\n", ['POST', '/', '', $canonicalHeaders, $signedHeaders, hash('sha256', $body)]);
        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        $added['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $accessKey, $scope, $signedHeaders, hash_hmac('sha256', $stringToSign, $key),
        );

        return $added;
    }
}
