<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;

interface SmsSender
{
    /**
     * Deliver a message to an E.164 phone number.
     *
     * @throws DeliveryFailed
     */
    public function send(string $to, string $message): void;
}
