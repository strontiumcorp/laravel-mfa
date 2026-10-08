<?php

namespace StrontiumCorp\LaravelMfa\Factors;

use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Support\PhoneNumber;

final class SmsOtpFactor extends OtpFactor
{
    public function type(): FactorType
    {
        return FactorType::Sms;
    }

    protected function resolveDestination(MultiFactorAuthenticatable $user, array $input): string|FailureReason
    {
        // Equivalent mutant(s): a missing destination fails normalisation either way.
        $phone = PhoneNumber::normalize((string) ($input['destination'] ?? '')); // @pest-mutate-ignore: RemoveStringCast,EmptyStringToNotEmpty

        if ($phone === null || ! $this->destinationAllowed($phone)) {
            return FailureReason::DestinationNotAllowed;
        }

        return $phone;
    }

    protected function destinationAllowed(string $destination): bool
    {
        return PhoneNumber::canReceive(
            $destination,
            $this->config['allowed_calling_codes'] ?? [],
            $this->config['blocked_prefixes'] ?? [],
        );
    }
}
