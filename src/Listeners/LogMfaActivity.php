<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Log\LogManager;
use Psr\Log\LogLevel;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use Throwable;

final class LogMfaActivity
{
    private const LEVELS = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    public function __construct(private readonly LogManager $log) {}

    public function handle(MfaActivity $event): void
    {
        if (! config('mfa.observability.log.enabled')) {
            return;
        }

        $minimum = self::LEVELS[(string) config('mfa.observability.log.level')] ?? 1;

        if ((self::LEVELS[$event->level()] ?? 1) < $minimum) {
            return;
        }

        try {
            $this->log->channel(config('mfa.observability.log.channel'))
                ->log($event->level(), 'mfa.'.$event->name(), $event->toContext());
        } catch (Throwable $e) {
            report($e); // Observability must never break authentication.
        }
    }
}
