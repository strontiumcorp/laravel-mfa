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
//     <MfaEnableNudge {...useMfaNudge()} />           // in the app's global layout
//     <MfaIdleWarning {...useMfaIdleWarning()} />     // also there, for users with an idle timeout
//
// The same floating card carries two messages, never both at once: the
// turn-on-two-factor nudge (users without a method) and the "check coming up"
// reminder (users with one, when their verification ends soon: the end of a
// fixed lifetime window, config mfa.lifetime, or of a trusted browser's
// "don't ask again". "Verify now" opens the challenge early, "Later" hides it
// until the next verification). useMfaNudge() picks whichever applies, and
// shows the reminder on an open page when it becomes due;
// mfaNudgeProps(mfa).kind says which.
//
// It's a .ts file, so the app's pages glob (**/*.tsx) doesn't treat it as a page.
// Mirrors StrontiumCorp\LaravelMfa\Support\MfaContext; keep the two in sync.
import { Link, router, usePage } from '@inertiajs/react';
import { createElement, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';

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
    /**
     * The "check coming up" reminder before this session has to verify again, for MfaEnableNudge:
     * the end of its lifetime window (config mfa.lifetime) or of its trusted browser (mfa.trusted_browsers.reminder).
     */
    reverifyReminder: {
        /** Due now: a verified session whose verification ends soon, not on an MFA page, and not dismissed. */
        show: boolean;
        /** Why it ends; null when nothing is coming up (or the routes are off, or it was dismissed). */
        reason: 'lifetime' | 'trust' | null;
        /** ISO 8601: when the session has to verify again. */
        expiresAt: string | null;
        /** ISO 8601: when the reminder becomes due, so an open page can show it then. */
        showAt: string | null;
        title: string;
        /** Contains ":when", replaced with e.g. "in 25 minutes" (mfaReminderWhen()). */
        body: string;
        button: string;
        dismissLabel: string;
        /** The challenge in "verify early" mode (GET); null when the MFA routes are disabled. */
        verifyUrl: string | null;
        /** POST (no body) here to hide it until the next verification; null when the MFA routes are disabled. */
        dismissUrl: string | null;
    };
    /** The verified session's deadlines (config mfa.lifetime); null when it has none. Times are ISO 8601, null = off. */
    verification: {
        profile: string;
        /** The server's clock when this was sent, to correct for a client clock that is off. */
        now: string;
        /** When the fixed window ends (page visits are challenged from then on). */
        expiresAt: string | null;
        remindAt: string | null;
        /** When the grace period ends (every request is challenged from then on). */
        graceUntil: string | null;
        idleSeconds: number | null;
        /** When the idle timeout ends, counted from this page's request. */
        idleExpiresAt: string | null;
        renewUrl: string | null;
        /** POST here for "Stay signed in" (204). */
        keepAliveUrl: string | null;
        /** GET the current deadlines ({ verification }); never counts as activity. */
        stateUrl: string | null;
    } | null;
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
    /** Which message: the turn-on nudge, or the "check coming up" reminder. */
    kind: 'enable' | 'reminder';
    title: string;
    body: string;
    button: string;
    dismissLabel: string;
    /** Where the button goes: the settings page (enable) or the challenge's "verify early" mode (reminder). */
    settingsUrl: string | null;
    dismissUrl: string | null;
    /** The × button's accessible name. */
    closeLabel: string;
};

/**
 * When the session has to verify again, for the reminder's ":when":
 * "in a minute", "in 40 minutes", "in an hour", "in 5 hours" (rounded down),
 * or "soon" when it's past or unknown.
 */
export function mfaReminderWhen(expiresAt: string | null | undefined, now: number = Date.now()): string {
    const at = expiresAt ? new Date(expiresAt).getTime() : NaN;
    if (Number.isNaN(at) || at <= now) return 'soon';
    // Rounded down, so it never promises more time than is left.
    const minutes = Math.floor((at - now) / 60_000);
    if (minutes < 2) return 'in a minute';
    if (minutes < 60) return `in ${minutes} minutes`;
    const hours = Math.floor(minutes / 60);

    return hours === 1 ? 'in an hour' : `in ${hours} hours`;
}

/** Whether the reminder is due at `now`: the server says so, or its showAt has come (on a page opened earlier). */
function reminderDue(reminder: MfaContext['reverifyReminder'] | undefined, now: number): boolean {
    if (!reminder?.reason) return false;
    if (reminder.show) return true;
    const at = reminder.showAt ? new Date(reminder.showAt).getTime() : NaN;

    return !Number.isNaN(at) && at <= now;
}

/**
 * Props for MfaEnableNudge, except onDismiss and renderLink (useMfaNudge()
 * adds both): the turn-on nudge when the server shows it, else the "check
 * coming up" reminder once it is due (at `now`); hidden when MFA or its
 * routes are off, or without a context.
 */
export function mfaNudgeProps(mfa: MfaContext | null, now: number = Date.now()): MfaNudgeProps {
    const nudge = mfa?.nudge;
    const reminder = mfa?.reverifyReminder;

    if (!(mfa?.enabled && nudge?.show) && mfa?.enabled && reminder && reminderDue(reminder, now) && reminder.verifyUrl && reminder.dismissUrl) {
        return {
            show: true,
            kind: 'reminder',
            title: reminder.title,
            body: reminder.body.replace(/:when/g, mfaReminderWhen(reminder.expiresAt, now)),
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
 * "check coming up" reminder, "Later" posts nothing to its own dismiss URL
 * and "Verify now" links to the challenge. The reminder appears on an open
 * page when it becomes due, and its ":when" stays current. Pass position,
 * offset or className next to it.
 */
export function useMfaNudge(): MfaNudgeProps & {
    onDismiss: (timezone: string | undefined) => void;
    renderLink: (link: { href: string; className: string; children: ReactNode }) => ReactNode;
} {
    const mfa = useMfa();
    const reminder = mfa?.reverifyReminder?.reason ? mfa.reverifyReminder : null;
    // Until the session has to verify again (and its grace, for the lifetime window).
    const until = reminder?.reason === 'lifetime' ? (mfa?.verification?.graceUntil ?? reminder.expiresAt) : reminder?.expiresAt;
    const now = useMinuteClock(reminder?.showAt ?? null, until ?? null);
    const props = mfaNudgeProps(mfa, now);

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

// setTimeout's longest delay is about 24.8 days; a trust reminder can be further off.
const MAX_DELAY = 2 ** 31 - 1;

/**
 * The current time, re-rendering once at `at` (when it is still ahead) and
 * every minute after it until `until`, so a reminder appears on a page
 * opened earlier and its ":when" stays current. Re-renders nothing while
 * `at` is null, and nothing once `until` has passed.
 */
function useMinuteClock(at: string | null | undefined, until: string | null | undefined): number {
    const [, setTick] = useState(0);

    useEffect(() => {
        const target = at ? new Date(at).getTime() : NaN;
        if (Number.isNaN(target)) return;
        const end = until ? new Date(until).getTime() : NaN;
        let timer: ReturnType<typeof setTimeout>;
        const schedule = () => {
            const current = Date.now();
            if (!Number.isNaN(end) && current > end) return;
            const wait = target > current ? Math.min(target - current, MAX_DELAY) : 60_000 - (current % 60_000);
            timer = setTimeout(() => {
                setTick((tick) => tick + 1);
                schedule();
            }, wait);
        };
        schedule();

        return () => clearTimeout(timer);
    }, [at, until]);

    return Date.now();
}

/** The XSRF-TOKEN cookie Laravel sets, for the keep-alive POST (as axios sends it). */
function xsrfToken(): string | null {
    const match = typeof document === 'undefined' ? null : document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * A server deadline on this browser's clock: shifted by how far the server's
 * `now` is from the client's, so a clock that is off doesn't warn early or late.
 */
function onLocalClock(deadline: string | null | undefined, serverNow: string | null | undefined): string | null {
    const at = deadline ? new Date(deadline).getTime() : NaN;
    if (Number.isNaN(at)) return null;
    const server = serverNow ? new Date(serverNow).getTime() : NaN;

    return new Date(Number.isNaN(server) ? at : at + (Date.now() - server)).toISOString();
}

/** The session's current idle deadline from the server (another tab may have been active); null if it has none or the call fails. */
async function fetchIdleExpiresAt(stateUrl: string): Promise<string | null> {
    const response = await fetch(stateUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) return null;
    const data = (await response.json()) as { verification?: { idleExpiresAt?: string | null; now?: string } | null };

    return onLocalClock(data.verification?.idleExpiresAt, data.verification?.now);
}

export type MfaIdleWarningHookProps = {
    /** ISO 8601: when the idle timeout ends; null = no idle timeout (renders nothing). */
    idleExpiresAt: string | null;
    /** "Stay signed in": tells the server, resolves with the new deadline. */
    onStay: () => Promise<string | null>;
    /** Asks the server for the deadline before warning (another tab may have been active). */
    refresh: () => Promise<string | null>;
};

/**
 * Every prop MfaIdleWarning needs, for the app's global layout:
 *
 *     <MfaIdleWarning {...useMfaIdleWarning()} />
 *
 * Renders nothing for sessions without an idle timeout. "Stay signed in"
 * POSTs to the keep-alive URL with fetch() (the XSRF-TOKEN cookie as the
 * header, like axios), so the page doesn't reload. Deadlines are moved onto
 * this browser's clock (the server sends its own `now`).
 */
export function useMfaIdleWarning(): MfaIdleWarningHookProps {
    const mfa = useMfa();
    const verification = mfa?.enabled && mfa.user?.verified ? mfa.verification : null;
    const stateUrl = verification?.stateUrl ?? null;
    const keepAliveUrl = verification?.keepAliveUrl ?? null;

    const refresh = useCallback(async () => (stateUrl ? fetchIdleExpiresAt(stateUrl).catch(() => null) : null), [stateUrl]);
    const onStay = useCallback(async () => {
        if (!keepAliveUrl) return null;
        const token = xsrfToken();
        const response = await fetch(keepAliveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(token ? { 'X-XSRF-TOKEN': token } : {}) },
        }).catch(() => null);

        return response?.ok ? refresh() : null;
    }, [keepAliveUrl, refresh]);

    // Moved onto this browser's clock once per answer from the server, so a
    // re-render of the layout never moves it (and never restarts the warning).
    const serverIdle = keepAliveUrl ? (verification?.idleExpiresAt ?? null) : null;
    const serverNow = verification?.now ?? null;
    const idleExpiresAt = useMemo(() => onLocalClock(serverIdle, serverNow), [serverIdle, serverNow]);

    return {
        idleExpiresAt,
        onStay,
        refresh,
    };
}
