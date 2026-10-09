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
//     <MfaSettingsCard {...mfaSettingsCardProps(useMfa())} renderLink={(link) => <Link {...link} />} />
//     <MfaEnableNudge {...useMfaNudge()} />   // in the app's global layout
//
// It's a .ts file, so the app's pages glob (**/*.tsx) doesn't treat it as a page.
// Mirrors StrontiumCorp\LaravelMfa\Support\MfaContext; keep the two in sync.
import { Link, router, usePage } from '@inertiajs/react';
import { createElement, type ReactNode } from 'react';

export type MfaFactorType = 'totp' | 'email' | 'sms';

export type MfaContext = {
    /** Global switch (MFA_ENABLED). */
    enabled: boolean;
    /** Factor types users can enroll. */
    factors: MfaFactorType[];
    /** Adding/removing factors may ask for the password first (routes.password_confirmation or routes.confirm_middleware). */
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
    /** The turn-on-two-factor nudge (config mfa.nudge), for MfaEnableNudge. */
    nudge: {
        /** A logged-in user with no method who isn't enforced, not on an MFA page, and hasn't dismissed it today. */
        show: boolean;
        title: string;
        body: string;
        button: string;
        dismissLabel: string;
        /** POST { timezone } here to hide it until the user's next midnight; null when the MFA routes are disabled. */
        dismissUrl: string | null;
    };
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

/**
 * Props for MfaSettingsCard, the account-settings entry: hidden when MFA is
 * off, the MFA routes are off, or the app doesn't share the context.
 */
export function mfaSettingsCardProps(mfa: MfaContext | null): { enabled: boolean; settingsUrl: string | null; hasMfa: boolean; mustEnroll: boolean } {
    return {
        enabled: mfa?.enabled ?? false,
        settingsUrl: mfa?.urls.settings ?? null,
        hasMfa: mfa?.user?.hasMfa ?? false,
        mustEnroll: mfa?.user?.mustEnroll ?? false,
    };
}

export type MfaNudgeProps = {
    show: boolean;
    title: string;
    body: string;
    button: string;
    dismissLabel: string;
    settingsUrl: string | null;
    dismissUrl: string | null;
};

/**
 * Props for MfaEnableNudge, except onDismiss and renderLink (useMfaNudge()
 * adds both): hidden when MFA or its routes are off, or without a context.
 */
export function mfaNudgeProps(mfa: MfaContext | null): MfaNudgeProps {
    const nudge = mfa?.nudge;

    return {
        show: !!(mfa?.enabled && nudge?.show && nudge.dismissUrl && mfa.urls.settings),
        title: nudge?.title ?? '',
        body: nudge?.body ?? '',
        button: nudge?.button ?? '',
        dismissLabel: nudge?.dismissLabel ?? '',
        settingsUrl: mfa?.urls.settings ?? null,
        dismissUrl: nudge?.dismissUrl ?? null,
    };
}

/**
 * Every prop MfaEnableNudge needs, for the app's global layout:
 *
 *     <MfaEnableNudge {...useMfaNudge()} />
 *
 * "Not today" posts the browser's timezone to the dismiss URL, staying on
 * the page; the button is Inertia's <Link> to the settings page. Pass
 * position, offset or className next to it.
 */
export function useMfaNudge(): MfaNudgeProps & {
    onDismiss: (timezone: string | undefined) => void;
    renderLink: (link: { href: string; className: string; children: ReactNode }) => ReactNode;
} {
    const props = mfaNudgeProps(useMfa());

    return {
        ...props,
        onDismiss: (timezone) => {
            if (props.dismissUrl) router.post(props.dismissUrl, timezone ? { timezone } : {}, { preserveScroll: true, preserveState: true });
        },
        renderLink: (link) => createElement(Link, link),
    };
}
