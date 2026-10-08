<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use Illuminate\Log\LogManager;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;

/**
 * Development driver: writes the message to the log instead of sending it.
 */
final class LogSmsSender implements SmsSender
{
    public function __construct(
        private readonly LogManager $log,
        private readonly ?string $channel = null,
    ) {}

    public function send(string $to, string $message): void
    {
        $this->log->channel($this->channel)->info('[mfa] SMS (log driver)', ['to' => $to, 'message' => $message]);
    }
}
