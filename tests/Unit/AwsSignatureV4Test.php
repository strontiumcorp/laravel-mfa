<?php

use Carbon\CarbonImmutable;
use StrontiumCorp\LaravelMfa\Sms\Aws\SignatureV4;

/*
 * Expected values were produced by the official aws/aws-sdk-php SignatureV4
 * (clock pinned to 2024-01-15T12:00:00Z) for exactly this request.
 */
$body = 'Action=Publish&Message=123456%20is%20your%20code&PhoneNumber=%2B15555550100&Version=2010-03-31';

it('matches the official AWS SDK signature', function (string $region, ?string $token, string $signature) use ($body) {
    $headers = SignatureV4::sign(
        host: "sns.{$region}.amazonaws.com",
        headers: ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
        body: $body,
        service: 'sns',
        region: $region,
        accessKey: 'AKIAIOSFODNN7EXAMPLE',
        secretKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        sessionToken: $token,
        at: CarbonImmutable::parse('2024-01-15T12:00:00Z'),
    );

    $signed = $token === null ? 'content-type;host;x-amz-date' : 'content-type;host;x-amz-date;x-amz-security-token';

    expect($headers['X-Amz-Date'])->toBe('20240115T120000Z')
        ->and($headers['Authorization'])->toBe("AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20240115/{$region}/sns/aws4_request, SignedHeaders={$signed}, Signature={$signature}")
        ->and($headers['X-Amz-Security-Token'] ?? null)->toBe($token);
})->with([
    'us-east-1' => ['us-east-1', null, '9d704c1c6c78677576f19a037c169aa4286ee26b88e3cb054851082961105b48'],
    'ap-southeast-1' => ['ap-southeast-1', null, '32e3745b8d5dee6391d145595c2c2106c455655094560123978c5f301805c04f'],
    'us-east-1 + session token' => ['us-east-1', 'SESSIONTOKEN123', '185c735ffbb45284bdfdfedaf8dba984ced183799baadf6cd25c729344c38848'],
    'ap-southeast-1 + session token' => ['ap-southeast-1', 'SESSIONTOKEN123', 'c9974776304ae5894fd1bc3f983e4dd1b74973b217bbdaa72cfb03585774c420'],
]);

it('normalises header whitespace before signing', function () use ($body) {
    $sign = fn (string $contentType) => SignatureV4::sign('sns.us-east-1.amazonaws.com', ['Content-Type' => $contentType], $body, 'sns', 'us-east-1', 'AKID', 'secret', null, CarbonImmutable::parse('2024-01-15T12:00:00Z'))['Authorization'];

    expect($sign('  application/x-www-form-urlencoded;   charset=utf-8 '))->toBe($sign('application/x-www-form-urlencoded; charset=utf-8'));
});
