<?php

namespace StrontiumCorp\LaravelMfa\Enums;

/**
 * Why an administrator's enrollment link was refused (VerificationFailed
 * with reason invalid_link, context "problem"). A bad signature or an
 * expired link never gets this far: the route's signature check refuses it.
 */
enum EnrollmentLinkProblem: string
{
    /** Opened in a session signed in as someone else. */
    case OtherAccount = 'other_account';
    /** The user's password changed since it was issued. */
    case Revoked = 'revoked';
    /** It has been used once already. */
    case Used = 'used';

    public function message(): string
    {
        return match ($this) {
            self::OtherAccount => 'This setup link is for another account.',
            self::Revoked => 'This setup link is no longer valid. Ask for a new one.',
            self::Used => 'This setup link has already been used. Ask for a new one.',
        };
    }
}
