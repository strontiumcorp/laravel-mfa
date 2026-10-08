<?php

use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Events\MfaEvent;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('env() is only read in config files (config:cache safe)')
    ->expect('StrontiumCorp\LaravelMfa')
    ->not->toUse('env');

arch('contracts are interfaces')
    ->expect('StrontiumCorp\LaravelMfa\Contracts')
    ->toBeInterfaces();

arch('enums are enums')
    ->expect('StrontiumCorp\LaravelMfa\Enums')
    ->toBeEnums();

arch('every event extends MfaEvent, so observability sees it')
    ->expect('StrontiumCorp\LaravelMfa\Events')
    ->classes()
    ->toExtend(MfaEvent::class)
    ->ignoring(MfaEvent::class);

arch('domain services do not depend on the HTTP layer')
    ->expect(['StrontiumCorp\LaravelMfa\Support', 'StrontiumCorp\LaravelMfa\Factors', 'StrontiumCorp\LaravelMfa\Models'])
    ->not->toUse('StrontiumCorp\LaravelMfa\Http');

arch('SMS drivers implement the contract')
    ->expect('StrontiumCorp\LaravelMfa\Sms')
    ->classes()
    ->toImplement(SmsSender::class)
    ->ignoring([SmsManager::class, 'StrontiumCorp\LaravelMfa\Sms\Aws']); // Sms\Aws holds signing helpers
