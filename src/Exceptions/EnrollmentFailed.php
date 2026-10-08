<?php

namespace StrontiumCorp\LaravelMfa\Exceptions;

use RuntimeException;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;

class EnrollmentFailed extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(public readonly FailureReason $reason, public readonly array $context = [])
    {
        parent::__construct($reason->message());
    }
}
