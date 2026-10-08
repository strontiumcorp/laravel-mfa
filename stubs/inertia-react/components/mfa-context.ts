// Published by strontiumcorp/laravel-mfa. This file is yours.
//
// The MFA state shared with every page. On the server, share it under the
// "mfa" key in HandleInertiaRequests::share():
//
//     'mfa' => fn () => \StrontiumCorp\LaravelMfa\Facades\Mfa::context($request),
//
// Mirrors StrontiumCorp\LaravelMfa\Support\MfaContext; keep the two in sync.
import { usePage } from '@inertiajs/react';

export type MfaFactorType = 'totp' | 'email' | 'sms';

export type MfaContext = {
    /** Global switch (MFA_ENABLED). */
    enabled: boolean;
    /** Factor types users can enroll. */
    factors: MfaFactorType[];
    /** Adding/removing factors asks for the password first (routes.confirm_middleware). */
    passwordConfirmation: boolean;
    /** The logged-in user; null for guests. */
    user: {
        hasMfa: boolean;
        /** This session has passed MFA. */
        verified: boolean;
        /** An enforcement rule requires this user to enroll. */
        mustEnroll: boolean;
    } | null;
    /** null when the MFA routes are disabled. */
    urls: { settings: string | null; challenge: string | null };
};

/** The shared MFA context, or null if the app doesn't share it. */
export function useMfa(): MfaContext | null {
    const props = usePage().props as { mfa?: MfaContext };

    return props.mfa ?? null;
}
