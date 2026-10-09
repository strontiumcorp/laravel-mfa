// Published by strontiumcorp/laravel-mfa. This file is yours.
//
// The MFA state shared with every page. On the server, share it under the
// "mfa" key in HandleInertiaRequests::share():
//
//     'mfa' => fn () => \StrontiumCorp\LaravelMfa\Facades\Mfa::context($request),
//
// This is the Inertia side: the components in components/vendor/laravel-mfa/
// never read it themselves. Pass them what they need, for example:
//
//     <MfaApiKeyNotice {...mfaApiKeyNoticeProps(useMfa())} />
//
// It's a .ts file, so the app's pages glob (**/*.tsx) doesn't treat it as a page.
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

/**
 * Props for MfaApiKeyNotice: hidden when MFA is off, and links to the
 * settings page for a user who has no MFA yet. Without a shared context the
 * notice still shows, without the link.
 */
export function mfaApiKeyNoticeProps(mfa: MfaContext | null): { enabled: boolean; settingsUrl: string | null } {
    return {
        enabled: mfa?.enabled ?? true,
        settingsUrl: mfa?.user && !mfa.user.hasMfa ? mfa.urls.settings : null,
    };
}
