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
// The same floating card carries two messages, never both at once: the
// turn-on-two-factor nudge (users without a method) and the trusted browser
// reminder (users with one, on a browser whose "don't ask again" ends within
// hours: "Verify now" opens the challenge early, "Later" hides it for the
// session). useMfaNudge() picks whichever applies; mfaNudgeProps(mfa).kind says which.
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
    /** The reminder before a trusted browser has to verify again (config mfa.trusted_browsers.reminder), for MfaEnableNudge. */
    trustReminder: {
        /** A verified session on a trusted browser whose trust ends soon, not on an MFA page, and not dismissed this session. */
        show: boolean;
        /** ISO 8601: when this browser has to verify again. */
        expiresAt: string | null;
        title: string;
        /** Contains ":when", replaced with e.g. "in 5 hours" (mfaTrustReminderWhen()). */
        body: string;
        button: string;
        dismissLabel: string;
        /** The challenge in "verify early" mode (GET); null when the MFA routes are disabled. */
        verifyUrl: string | null;
        /** POST (no body) here to hide it for the rest of the session; null when the MFA routes are disabled. */
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
    /** Which message: the turn-on nudge, or the trusted browser reminder. */
    kind: 'enable' | 'trust-reminder';
    title: string;
    body: string;
    button: string;
    dismissLabel: string;
    /** Where the button goes: the settings page (enable) or the challenge's "verify early" mode (trust-reminder). */
    settingsUrl: string | null;
    dismissUrl: string | null;
    /** The × button's accessible name. */
    closeLabel: string;
};

/**
 * When a trusted browser has to verify again, for the reminder's ":when":
 * "in a minute", "in 40 minutes", "in an hour", "in 5 hours" (rounded up),
 * or "soon" when it's past or unknown.
 */
export function mfaTrustReminderWhen(expiresAt: string | null | undefined, now: number = Date.now()): string {
    const at = expiresAt ? new Date(expiresAt).getTime() : NaN;
    if (Number.isNaN(at) || at <= now) return 'soon';
    // Rounded down, so it never promises more time than is left.
    const minutes = Math.floor((at - now) / 60_000);
    if (minutes < 2) return 'in a minute';
    if (minutes < 60) return `in ${minutes} minutes`;
    const hours = Math.floor(minutes / 60);

    return hours === 1 ? 'in an hour' : `in ${hours} hours`;
}

/**
 * Props for MfaEnableNudge, except onDismiss and renderLink (useMfaNudge()
 * adds both): the turn-on nudge when the server shows it, else the trusted
 * browser reminder when the server shows that; hidden when MFA or its routes
 * are off, or without a context.
 */
export function mfaNudgeProps(mfa: MfaContext | null): MfaNudgeProps {
    const nudge = mfa?.nudge;
    const reminder = mfa?.trustReminder;

    if (!(mfa?.enabled && nudge?.show) && mfa?.enabled && reminder?.show && reminder.verifyUrl && reminder.dismissUrl) {
        return {
            show: true,
            kind: 'trust-reminder',
            title: reminder.title,
            body: reminder.body.replace(/:when/g, mfaTrustReminderWhen(reminder.expiresAt)),
            button: reminder.button,
            dismissLabel: reminder.dismissLabel,
            settingsUrl: reminder.verifyUrl,
            dismissUrl: reminder.dismissUrl,
            closeLabel: 'Dismiss',
        };
    }

    return {
        show: !!(mfa?.enabled && nudge?.show && nudge.dismissUrl && mfa.urls.settings),
        kind: 'enable',
        title: nudge?.title ?? '',
        body: nudge?.body ?? '',
        button: nudge?.button ?? '',
        dismissLabel: nudge?.dismissLabel ?? '',
        settingsUrl: mfa?.urls.settings ?? null,
        dismissUrl: nudge?.dismissUrl ?? null,
        closeLabel: 'Dismiss for today',
    };
}

/**
 * Every prop MfaEnableNudge needs, for the app's global layout:
 *
 *     <MfaEnableNudge {...useMfaNudge()} />
 *
 * "Not today" posts the browser's timezone to the dismiss URL, staying on
 * the page; the button is Inertia's <Link> to the settings page. For the
 * trusted browser reminder, "Later" posts nothing to its own dismiss URL and
 * "Verify now" links to the challenge. Pass position, offset or className
 * next to it.
 */
export function useMfaNudge(): MfaNudgeProps & {
    onDismiss: (timezone: string | undefined) => void;
    renderLink: (link: { href: string; className: string; children: ReactNode }) => ReactNode;
} {
    const props = mfaNudgeProps(useMfa());

    return {
        ...props,
        onDismiss: (timezone) => {
            if (!props.dismissUrl) return;
            const data = props.kind === 'enable' && timezone ? { timezone } : {};
            router.post(props.dismissUrl, data, { preserveScroll: true, preserveState: true });
        },
        renderLink: (link) => createElement(Link, link),
    };
}
