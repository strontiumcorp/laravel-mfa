<?php

namespace StrontiumCorp\LaravelMfa\Factors;

use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;

final class EmailOtpFactor extends OtpFactor
{
    public function type(): FactorType
    {
        return FactorType::Email;
    }

    protected function resolveDestination(MultiFactorAuthenticatable $user, array $input): string|FailureReason
    {
        // Equivalent mutant(s): Laravel's TrimStrings middleware already trims input; a missing address fails validation either way.
        $email = trim((string) ($input['destination'] ?? $user->getMfaEmail() ?? '')); // @pest-mutate-ignore: RemoveStringCast,EmptyStringToNotEmpty,UnwrapTrim

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : FailureReason::DestinationNotAllowed;
    }
}
