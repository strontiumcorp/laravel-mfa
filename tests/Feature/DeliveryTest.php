<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ChallengeDeliveryFailed;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\FactorManager;
use StrontiumCorp\LaravelMfa\Jobs\DeliverOtp;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;

it('queues delivery with an encrypted payload when configured', function () {
    Queue::fake();
    config(['mfa.delivery.queue_connection' => 'redis', 'mfa.delivery.queue' => 'mfa']);
    app(FactorManager::class)->forgetDrivers();
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);

    $this->loginWithSession($user)->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();

    Queue::assertPushedOn('mfa', DeliverOtp::class, fn (DeliverOtp $job) => $job->factorId === $factor->id && $job->connection === 'redis');
    expect(new DeliverOtp(1, '123456', 300))->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('emits a delivery-failed event when queued retries are exhausted', function () {
    Event::fake([ChallengeDeliveryFailed::class]);
    [, $factor] = $this->userWithFactor(FactorType::Sms);

    (new DeliverOtp($factor->id, '123456', 300))->failed(new DeliveryFailed('[twilio] HTTP 500'));

    Event::assertDispatched(ChallengeDeliveryFailed::class, fn ($e) => $e->context['queued'] && $e->context['error'] === '[twilio] HTTP 500');
});

it('sends via Twilio with a messaging service or from-number', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    config(['mfa.sms.drivers.twilio' => ['sid' => 'AC123', 'token' => 'secret', 'from' => '+15550000000']]);

    app(SmsManager::class)->driver('twilio')->send('+15555550100', 'hi');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
        && $r['To'] === '+15555550100' && $r['From'] === '+15550000000' && $r['Body'] === 'hi'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret')));
});

it('turns Twilio errors into DeliveryFailed without leaking the body', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'Invalid To +15555550100'], 400)]);
    config(['mfa.sms.drivers.twilio' => ['sid' => 'AC123', 'token' => 'secret', 'from' => '+1555']]);

    expect(fn () => app(SmsManager::class)->driver('twilio')->send('+15555550100', 'hi'))
        ->toThrow(DeliveryFailed::class, '[twilio] HTTP 400, code 21211');
});

it('sends via Vonage and checks the per-message status', function () {
    Http::fake(['rest.nexmo.com/*' => Http::response(['messages' => [['status' => '0']]])]);
    config(['mfa.sms.drivers.vonage' => ['key' => 'k', 'secret' => 's', 'from' => 'App']]);

    app(SmsManager::class)->driver('vonage')->send('+15555550100', 'hi');
    Http::assertSent(fn (Request $r) => $r['to'] === '15555550100' && $r['api_key'] === 'k');
});

it('treats a non-zero Vonage message status as a failure', function () {
    Http::fake(['rest.nexmo.com/*' => Http::response(['messages' => [['status' => '9']]])]);
    config(['mfa.sms.drivers.vonage' => ['key' => 'k', 'secret' => 's', 'from' => 'App']]);

    expect(fn () => app(SmsManager::class)->driver('vonage')->send('+15555550100', 'hi'))
        ->toThrow(DeliveryFailed::class, 'status 9');
});

it('supports custom SMS drivers with their own config', function () {
    $sent = new ArrayObject;
    config(['mfa.sms.driver' => 'acme', 'mfa.sms.drivers.acme' => ['token' => 't0k']]);

    Mfa::extendSms('acme', fn ($app, array $config) => new class($config, $sent) implements SmsSender
    {
        public function __construct(private array $config, private ArrayObject $sent) {}

        public function send(string $to, string $message): void
        {
            $this->sent[] = [$this->config['token'], $to];
        }
    });

    app(SmsSender::class)->send('+15555550100', 'hi');

    expect($sent->getArrayCopy())->toBe([['t0k', '+15555550100']]);
});

it('renders the SMS message template', function () {
    $sms = Mfa::fakeSms();
    Mfa::fakeCodes('555123');
    config(['app.name' => 'Artistly']);
    [, $factor] = $this->userWithFactor(FactorType::Sms);

    Mfa::factor(FactorType::Sms)->challenge($factor);

    expect($sms->sent[0]['message'])->toBe('555123 is your Artistly verification code. It expires in 10 minutes.');
});
