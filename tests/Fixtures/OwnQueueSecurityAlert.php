<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use StrontiumCorp\LaravelMfa\Notifications\SecurityAlertNotification;

/** A replacement security alert that picks its own queue. */
class OwnQueueSecurityAlert extends SecurityAlertNotification
{
    public function __construct(string $alert, array $details = [])
    {
        parent::__construct($alert, $details);
        $this->onQueue('alerts');
    }
}
