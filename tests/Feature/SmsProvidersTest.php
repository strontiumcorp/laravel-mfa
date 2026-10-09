<?php

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ChallengeDeliveryFailed;
use StrontiumCorp\LaravelMfa\Events\SmsProviderFailed;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Sms\Aws\SignatureV4;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;

function sms(?string $driver = null): SmsSender
{
    return app(SmsManager::class)->driver($driver);
}

/** A custom driver that records sends and can be told to fail. */
function recordingDriver(string $name, ArrayObject $log, ?Throwable $fail = null): void
{
    Mfa::extendSms($name, fn () => new class($name, $log, $fail) implements SmsSender
    {
        public function __construct(private string $name, private ArrayObject $log, private ?Throwable $fail) {}

        public function send(string $to, string $message): void
        {
            $this->log[] = "{$this->name}:{$to}";
            if ($this->fail) {
                throw $this->fail;
            }
        }
    });
}

describe('sns', function () {
    beforeEach(fn () => config(['mfa.sms.drivers.sns' => [
        'key' => 'AKIAIOSFODNN7EXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        'region' => 'eu-west-1', 'sms_type' => 'Transactional', 'sender_id' => 'Artistly', 'origination_number' => null,
    ]]));

    it('publishes a signed, transactional SMS', function () {
        $this->travelTo(CarbonImmutable::parse('2024-01-15T12:00:00Z'));
        Http::fake(['sns.eu-west-1.amazonaws.com/*' => Http::response('<PublishResponse><PublishResult><MessageId>abc</MessageId></PublishResult></PublishResponse>')]);

        sms('sns')->send('+15555550100', '123456 is your code');

        Http::assertSent(function (Request $r) {
            // Raw body (parse_str() would mangle the dotted attribute names).
            $expectedBody = 'Action=Publish&Version=2010-03-31&PhoneNumber=%2B15555550100&Message=123456%20is%20your%20code'
                .'&MessageAttributes.entry.1.Name=AWS.SNS.SMS.SMSType&MessageAttributes.entry.1.Value.DataType=String&MessageAttributes.entry.1.Value.StringValue=Transactional'
                .'&MessageAttributes.entry.2.Name=AWS.SNS.SMS.SenderID&MessageAttributes.entry.2.Value.DataType=String&MessageAttributes.entry.2.Value.StringValue=Artistly';
            $expected = SignatureV4::sign('sns.eu-west-1.amazonaws.com', ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'], $expectedBody, 'sns', 'eu-west-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', null, now());

            return $r->url() === 'https://sns.eu-west-1.amazonaws.com/' && $r->method() === 'POST'
                && $r->body() === $expectedBody                                   // origination number omitted when empty
                && $r->header('Content-Type') === ['application/x-www-form-urlencoded; charset=utf-8']
                && $r->header('Authorization')[0] === $expected['Authorization']
                && $r->header('X-Amz-Date')[0] === '20240115T120000Z';
        });
    });

    it('sends the session token for temporary credentials', function () {
        config(['mfa.sms.drivers.sns.token' => 'TEMP']);
        Http::fake(['*' => Http::response('<MessageId>x</MessageId>')]);

        sms('sns')->send('+15555550100', 'hi');

        Http::assertSent(fn (Request $r) => $r->header('X-Amz-Security-Token') === ['TEMP'] && str_contains($r->header('Authorization')[0], 'x-amz-security-token'));
    });

    it('turns AWS errors into DeliveryFailed with the AWS error code', function () {
        Http::fake(['*' => Http::response('<ErrorResponse><Error><Type>Sender</Type><Code>InvalidParameter</Code><Message>Invalid parameter: PhoneNumber +15555550100</Message></Error></ErrorResponse>', 400)]);

        expect(fn () => sms('sns')->send('+15555550100', 'hi'))->toThrow(DeliveryFailed::class, '[sns] HTTP 400, code InvalidParameter');
    });

    it('treats a 200 without a MessageId as a failure', function () {
        Http::fake(['*' => Http::response('<html>proxy error</html>')]);

        expect(fn () => sms('sns')->send('+15555550100', 'hi'))->toThrow(DeliveryFailed::class, 'code unknown');
    });

    it('refuses a malformed region instead of building a URL from it', function () {
        Http::fake();
        config(['mfa.sms.drivers.sns.region' => 'evil.example.com/x']);

        expect(fn () => sms('sns')->send('+15555550100', 'hi'))->toThrow(DeliveryFailed::class, 'invalid region');
        Http::assertNothingSent();
    });

    it('reports connection failures', function () {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        expect(fn () => sms('sns')->send('+15555550100', 'hi'))->toThrow(DeliveryFailed::class, '[sns] connection: timed out');
    });
});

describe('infobip', function () {
    beforeEach(fn () => config(['mfa.sms.drivers.infobip' => ['api_key' => 'KEY', 'base_url' => 'https://abc123.api.infobip.com/', 'from' => 'Artistly']]));

    it('sends through the v3 messages API', function () {
        Http::fake(['abc123.api.infobip.com/*' => Http::response(['bulkId' => '1', 'messages' => [['messageId' => 'm1', 'status' => ['groupId' => 1, 'groupName' => 'PENDING', 'name' => 'PENDING_ACCEPTED']]]])]);

        sms('infobip')->send('+8801711000000', 'code 1');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://abc123.api.infobip.com/sms/3/messages'
            && $r->header('Authorization') === ['App KEY']
            && $r->data() === ['messages' => [['sender' => 'Artistly', 'destinations' => [['to' => '8801711000000']], 'content' => ['text' => 'code 1']]]]);
    });

    it('fails on a rejected message', function () {
        Http::fake(['*' => Http::response(['messages' => [['status' => ['groupName' => 'REJECTED', 'name' => 'REJECTED_DESTINATION_NOT_REGISTERED']]]])]);

        expect(fn () => sms('infobip')->send('+8801711000000', 'x'))->toThrow(DeliveryFailed::class, '[infobip] status REJECTED_DESTINATION_NOT_REGISTERED');
    });

    it('fails on API errors with the error code', function () {
        Http::fake(['*' => Http::response(['requestError' => ['serviceException' => ['messageId' => 'UNAUTHORIZED', 'text' => 'Invalid login details']]], 401)]);

        expect(fn () => sms('infobip')->send('+8801711000000', 'x'))->toThrow(DeliveryFailed::class, '[infobip] HTTP 401, code UNAUTHORIZED');
    });

    it('reports connection failures', function () {
        Http::fake(fn () => throw new ConnectionException('reset'));

        expect(fn () => sms('infobip')->send('+8801711000000', 'x'))->toThrow(DeliveryFailed::class, '[infobip] connection: reset');
    });
});

describe('failover', function () {
    it('uses the next provider when one fails, and records the failure', function () {
        Event::fake([SmsProviderFailed::class]);
        $log = new ArrayObject;
        recordingDriver('primary', $log, DeliveryFailed::provider('primary', 'HTTP 503'));
        recordingDriver('secondary', $log);
        config(['mfa.sms.drivers.failover' => ['drivers' => ['primary', 'secondary']]]);

        sms('failover')->send('+15555550100', 'hi');

        expect($log->getArrayCopy())->toBe(['primary:+15555550100', 'secondary:+15555550100']);
        Event::assertDispatched(SmsProviderFailed::class, fn ($e) => $e->context === ['provider' => 'primary', 'next' => 'secondary', 'error' => '[primary] HTTP 503']);
    });

    it('stops at the first provider that succeeds', function () {
        $log = new ArrayObject;
        recordingDriver('primary', $log);
        recordingDriver('secondary', $log);
        config(['mfa.sms.drivers.failover' => ['drivers' => ['primary', 'secondary']]]);

        sms('failover')->send('+15555550100', 'hi');

        expect($log->getArrayCopy())->toBe(['primary:+15555550100']);
    });

    it('fails with every reason when all providers fail', function () {
        recordingDriver('a', new ArrayObject, DeliveryFailed::provider('a', 'HTTP 500'));
        recordingDriver('b', new ArrayObject, DeliveryFailed::provider('b', 'HTTP 429'));
        config(['mfa.sms.drivers.failover' => ['drivers' => ['a', 'b']]]);

        expect(fn () => sms('failover')->send('+15555550100', 'hi'))
            ->toThrow(DeliveryFailed::class, '[failover] all providers failed — a: [a] HTTP 500; b: [b] HTTP 429');
    });

    it('fails over on a bug in a custom driver, reporting it without leaking its message', function () {
        Exceptions::fake();
        Event::fake([SmsProviderFailed::class]);
        recordingDriver('buggy', new ArrayObject, new RuntimeException('secret +15555550100'));
        recordingDriver('ok', new ArrayObject);
        config(['mfa.sms.drivers.failover' => ['drivers' => ['buggy', 'ok']]]);

        sms('failover')->send('+15555550100', 'hi');

        Exceptions::assertReported(RuntimeException::class);
        Event::assertDispatched(SmsProviderFailed::class, fn ($e) => $e->context['error'] === RuntimeException::class);
    });

    it('needs at least one driver', function () {
        config(['mfa.sms.drivers.failover' => ['drivers' => []]]);

        sms('failover');
    })->throws(InvalidArgumentException::class, 'at least one driver');

    it('goes through the whole pipeline: audit rows per failed provider, then the code is delivered', function () {
        Mfa::fakeCodes('424242');
        $log = new ArrayObject;
        recordingDriver('down', $log, DeliveryFailed::provider('down', 'HTTP 500'));
        recordingDriver('up', $log);
        config(['mfa.sms.driver' => 'failover', 'mfa.sms.drivers.failover' => ['drivers' => ['down', 'up']]]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();

        expect($log->getArrayCopy())->toBe(['down:+15555550100', 'up:+15555550100'])
            ->and(MfaAuditLog::where('event', 'sms_provider_failed')->sole()->context)->toMatchArray(['provider' => 'down', 'next' => 'up']);
    });

    it('raises ChallengeDeliveryFailed only when every provider failed', function () {
        Event::fake([ChallengeDeliveryFailed::class, SmsProviderFailed::class]);
        recordingDriver('down', new ArrayObject, DeliveryFailed::provider('down', 'HTTP 500'));
        config(['mfa.sms.driver' => 'failover', 'mfa.sms.drivers.failover' => ['drivers' => ['down']]]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertUnprocessable();

        Event::assertDispatchedTimes(SmsProviderFailed::class, 1);
        Event::assertDispatchedTimes(ChallengeDeliveryFailed::class, 1);
    });
});

describe('routing', function () {
    it('routes by the longest matching prefix, else the default', function () {
        $log = new ArrayObject;
        foreach (['bd', 'bd_gp', 'world'] as $name) {
            recordingDriver($name, $log);
        }
        config(['mfa.sms.drivers.routing' => ['routes' => ['880' => 'bd', '88017' => 'bd_gp'], 'default' => 'world']]);

        sms('routing')->send('+8801911000000', 'x');
        sms('routing')->send('+8801711000000', 'x');
        sms('routing')->send('+15555550100', 'x');

        expect($log->getArrayCopy())->toBe(['bd:+8801911000000', 'bd_gp:+8801711000000', 'world:+15555550100']);
    });

    it('accepts prefixes written with or without "+"', function () {
        $log = new ArrayObject;
        foreach (['bd', 'uk', 'world'] as $name) {
            recordingDriver($name, $log);
        }
        config(['mfa.sms.drivers.routing' => ['routes' => ['+880' => 'bd', '44' => 'uk'], 'default' => 'world']]);

        sms('routing')->send('+8801711000000', 'x');
        sms('routing')->send('+447700900000', 'x');

        expect($log->getArrayCopy())->toBe(['bd:+8801711000000', 'uk:+447700900000']);
    });

    it('can route a region to its own named failover chain', function () {
        $log = new ArrayObject;
        recordingDriver('infobipish', $log, DeliveryFailed::provider('infobipish', 'HTTP 500'));
        recordingDriver('twilioish', $log);
        config([
            'mfa.sms.drivers.bd' => ['transport' => 'failover', 'drivers' => ['infobipish', 'twilioish']],
            'mfa.sms.drivers.routing' => ['routes' => ['880' => 'bd'], 'default' => 'twilioish'],
        ]);

        sms('routing')->send('+8801711000000', 'x');

        expect($log->getArrayCopy())->toBe(['infobipish:+8801711000000', 'twilioish:+8801711000000']);
    });

    it('needs a default', function () {
        config(['mfa.sms.drivers.routing' => ['routes' => []]]);

        sms('routing');
    })->throws(InvalidArgumentException::class, 'needs a [default]');

    it('sends a Bangladesh login code through Infobip and the rest through Twilio, end to end', function () {
        Http::fake([
            'api.infobip.com/*' => Http::response(['messages' => [['status' => ['groupName' => 'PENDING', 'name' => 'PENDING_ACCEPTED']]]]),
            'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
        ]);
        config([
            'mfa.factors.sms.allowed_calling_codes' => ['1', '880'],
            'mfa.sms.driver' => 'routing',
            'mfa.sms.drivers.routing' => ['routes' => ['880' => 'infobip'], 'default' => 'twilio'],
            'mfa.sms.drivers.infobip' => ['api_key' => 'K', 'base_url' => 'https://api.infobip.com', 'from' => 'App'],
            'mfa.sms.drivers.twilio' => ['sid' => 'AC1', 'token' => 't', 'from' => '+15550000000'],
        ]);
        $bd = $this->makeUser();
        $bdFactor = $this->createMfaFactor($bd, FactorType::Sms, '+8801711000000');
        [$us, $usFactor] = $this->userWithFactor(FactorType::Sms);

        $this->loginWithSession($bd)->postJson('/mfa/challenge/send', ['factor_id' => $bdFactor->id])->assertOk();
        $this->freshGuards()->post('/logout');
        $this->loginWithSession($us)->postJson('/mfa/challenge/send', ['factor_id' => $usFactor->id])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'infobip') && $r['messages'][0]['destinations'][0]['to'] === '8801711000000');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'twilio') && $r['To'] === '+15555550100');
        Http::assertSentCount(2);
    });
});

describe('driver configuration', function () {
    it('lets a named driver pick its transport', function () {
        Http::fake(['*' => Http::response(['messages' => [['status' => ['groupName' => 'PENDING']]]])]);
        config(['mfa.sms.drivers.infobip_eu' => ['transport' => 'infobip', 'api_key' => 'EU', 'base_url' => 'https://eu.api.infobip.com', 'from' => 'X']]);

        sms('infobip_eu')->send('+447700900000', 'x');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://eu.api.infobip.com/sms/3/messages' && $r->header('Authorization') === ['App EU']);
    });

    it('refuses circular configurations instead of looping', function () {
        config([
            'mfa.sms.drivers.a' => ['transport' => 'failover', 'drivers' => ['b']],
            'mfa.sms.drivers.b' => ['transport' => 'routing', 'routes' => [], 'default' => 'a'],
        ]);

        sms('a');
    })->throws(InvalidArgumentException::class, 'Circular SMS driver configuration: a → b → a');

    it('rejects unknown drivers', function () {
        sms('nope');
    })->throws(InvalidArgumentException::class, 'SMS driver [nope] is not supported.');

    it('tags metrics with the failed provider', function () {
        $recorder = new class implements MetricsRecorder
        {
            public array $counters = [];

            public function increment(string $metric, array $tags = []): void
            {
                $this->counters[] = [$metric, $tags];
            }

            public function timing(string $metric, float $milliseconds, array $tags = []): void {}
        };
        app()->instance(MetricsRecorder::class, $recorder);
        recordingDriver('down', new ArrayObject, DeliveryFailed::provider('down', 'x'));
        recordingDriver('up', new ArrayObject);
        config(['mfa.sms.drivers.failover' => ['drivers' => ['down', 'up']]]);

        sms('failover')->send('+15555550100', 'hi');

        expect($recorder->counters)->toContain(['mfa.sms_provider_failed', ['factor' => 'sms', 'reason' => 'delivery_failed', 'provider' => 'down']]);
    });
});

describe('mfa:doctor SMS checks', function () {
    beforeEach(fn () => config(['session.driver' => 'database', 'mfa.factors.sms.enabled' => true]));

    it('reports missing settings anywhere in the chain', function () {
        config([
            'mfa.sms.driver' => 'routing',
            'mfa.sms.drivers.routing' => ['routes' => ['880' => 'infobip'], 'default' => 'failover'],
            'mfa.sms.drivers.failover' => ['drivers' => ['sns', 'twilio']],
            'mfa.sms.drivers.infobip' => ['api_key' => 'k', 'from' => null],
            'mfa.sms.drivers.sns' => ['key' => 'k', 'secret' => 's', 'region' => 'us-east-1'],
            'mfa.sms.drivers.twilio' => ['sid' => 'AC', 'token' => 't', 'from' => null, 'messaging_service_sid' => null],
        ]);

        $this->artisan('mfa:doctor')->assertFailed()
            ->expectsOutputToContain('[infobip] missing from')
            ->expectsOutputToContain('[twilio] missing from or messaging_service_sid');
    });

    it('passes a fully configured chain and catches circular references', function () {
        config([
            'mfa.sms.driver' => 'failover',
            'mfa.sms.drivers.failover' => ['drivers' => ['sns', 'infobip']],
            'mfa.sms.drivers.sns' => ['key' => 'k', 'secret' => 's', 'region' => 'us-east-1'],
            'mfa.sms.drivers.infobip' => ['api_key' => 'k', 'from' => 'App'],
        ]);
        $this->artisan('mfa:doctor')->assertSuccessful();

        config(['mfa.sms.drivers.failover' => ['drivers' => ['failover']]]);
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('circular reference failover → failover');
    });
});

describe('provider HTTP calls', function () {
    beforeEach(fn () => config(['mfa.sms.drivers.twilio' => ['sid' => 'AC0123456789abcdef', 'token' => 't', 'from' => '+15550000000']]));

    /** A transport error as Guzzle's curl handler reports it (Guzzle 7 or 8). */
    function transportError(bool $sent): TransferException
    {
        $message = 'cURL error 28: failed (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.twilio.com/2010-04-01/Accounts/AC0123456789abcdef/Messages.json';
        $request = new PsrRequest('POST', 'https://api.twilio.com/');

        if (class_exists(NetworkTimeoutException::class)) { // Guzzle 8: typed exceptions
            return $sent ? new NetworkTimeoutException($message, $request) : new ConnectException($message, $request);
        }

        return new ConnectException($message, $request, null, ['errno' => $sent ? 28 : 7, 'request_size' => $sent ? 512 : 0]);
    }

    it('retries when the request never reached the provider', function () {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw transportError(sent: false);
        });

        expect(fn () => sms('twilio')->send('+15555550100', 'hi'))->toThrow(DeliveryFailed::class);
        expect($attempts)->toBe(2);
    });

    it('does not retry a timeout after the message was sent (no duplicate SMS)', function () {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw transportError(sent: true);
        });

        expect(fn () => sms('twilio')->send('+15555550100', 'hi'))->toThrow(DeliveryFailed::class);
        expect($attempts)->toBe(1);
    });

    it('says whether a failed request may have delivered the message', function (bool $sent) {
        Http::fake(fn () => throw transportError(sent: $sent));

        try {
            sms('twilio')->send('+15555550100', 'hi');
        } catch (DeliveryFailed $e) {
        }

        expect($e->maybeDelivered)->toBe($sent);
    })->with(['read timeout after sending' => [true], 'never connected' => [false]]);

    it('stops the failover chain when a provider may have delivered the message (no duplicate SMS)', function () {
        Event::fake([SmsProviderFailed::class]);
        Http::fake(fn () => throw transportError(sent: true));
        $log = new ArrayObject;
        recordingDriver('backup', $log);
        config(['mfa.sms.drivers.failover' => ['drivers' => ['twilio', 'backup']]]);

        expect(fn () => sms('failover')->send('+15555550100', 'hi'))
            ->toThrow(fn (DeliveryFailed $e) => expect($e->maybeDelivered)->toBeTrue()
                ->and($e->getMessage())->toStartWith('[failover] twilio may have delivered it'));

        expect($log->getArrayCopy())->toBe([]);
        Event::assertDispatched(SmsProviderFailed::class, fn ($e) => $e->context['provider'] === 'twilio'
            && $e->context['next'] === null && $e->context['maybe_delivered'] === true);
    });

    it('still fails over when the provider was never reached', function () {
        Http::fake(fn () => throw transportError(sent: false));
        $log = new ArrayObject;
        recordingDriver('backup', $log);
        config(['mfa.sms.drivers.failover' => ['drivers' => ['twilio', 'backup']]]);

        sms('failover')->send('+15555550100', 'hi');

        expect($log->getArrayCopy())->toBe(['backup:+15555550100']);
    });

    it('keeps the code when an inline send may have been delivered, and says so in the event', function () {
        Event::fake([ChallengeDeliveryFailed::class]);
        Http::fake(fn () => throw transportError(sent: true));
        config(['mfa.sms.driver' => 'twilio']);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        // The user may well have it: the page says it was sent, with the
        // usual cooldown, and the code stays valid.
        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])
            ->assertOk()->assertJsonPath('status', 'code-sent');
        expect($factor->otpCodes()->whereNull('consumed_at')->count())->toBe(1);
        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertStatus(429);

        Event::assertDispatched(ChallengeDeliveryFailed::class, fn ($e) => $e->context['maybe_delivered'] === true);
    });

    it('keeps URLs (account ids) out of connection errors', function () {
        Http::fake(fn () => throw transportError(sent: true));

        try {
            sms('twilio')->send('+15555550100', 'hi');
        } catch (DeliveryFailed $e) {
        }

        expect($e->getMessage())->toStartWith('[twilio] connection: cURL error 28')
            ->not->toContain('AC0123456789abcdef')
            ->not->toContain('https://');
    });
});

it('redacts URLs, emails and phone numbers from event context before logging or auditing', function () {
    event(new ChallengeDeliveryFailed(null, FactorType::Sms, null, [
        'error' => 'failed for jane@example.com / +15555550100 at https://api.example.com/AC1/x',
        'factor_id' => 7,
    ]));

    expect(MfaAuditLog::sole()->context)->toBe([
        'error' => 'failed for [email] / [number] at [url]',
        'factor_id' => 7,
    ]);
});
