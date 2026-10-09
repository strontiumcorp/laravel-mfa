// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// A small floating card asking a user without two-factor to turn it on, for
// the app's global layout. "Not today" (or ×) hides it at once and tells the
// app, which hides it until the user's next local midnight. Standalone: needs
// only React and ./icons, and knows nothing about Inertia or routes. The same
// card also carries the trusted browser reminder ("Verify now" / "Later"):
// only the copy, the link and closeLabel differ, and useMfaNudge() fills them.
//
// In an Inertia app, useMfaNudge() from the MFA pages' mfa-context.ts gives
// every prop (the shared context, the dismiss request and Inertia's <Link>):
//
//     import MfaEnableNudge from '@/components/vendor/laravel-mfa/enable-nudge';
//     import { useMfaNudge } from '@/pages/mfa/mfa-context';
//
//     <MfaEnableNudge {...useMfaNudge()} />
//
// While an admin impersonates the user, pass disabled (the app's own
// "is impersonating" state): dismissing then hides it for this page view only
// and saves nothing, so the admin can't hide the user's nudge.
import { useEffect, useId, useState, type CSSProperties, type ReactNode } from 'react';
import { MfaIconClose, MfaIconShieldLock } from './icons';

export type MfaEnableNudgeLink = { href: string; className: string; children: ReactNode };

export type MfaEnableNudgePosition = 'top-left' | 'top-center' | 'top-right' | 'bottom-left' | 'bottom-center' | 'bottom-right';

/** A number is px, a string any CSS length; a pair is [x, y]. */
export type MfaEnableNudgeOffset = number | string | [number | string, number | string];

export type MfaEnableNudgeProps = {
    /** Renders nothing when false (the context's nudge.show). */
    show: boolean;
    title: string;
    body: string;
    /** The primary button, a link to the settings page. */
    button: string;
    /** The quiet "Not today" button. */
    dismissLabel: string;
    /** The MFA settings page; without it there is no primary button. */
    settingsUrl: string | null;
    /** Called on "Not today" or ×, with the browser's timezone (undefined if it can't tell). */
    onDismiss: (timezone: string | undefined) => void;
    /** The corner or edge it floats at; defaults to bottom-right. */
    position?: MfaEnableNudgePosition;
    /**
     * Distance from the chosen edges; defaults to 24 (px). For *-center the
     * horizontal value is ignored. Phones (below `sm`) always get the full
     * width with a 16px gutter.
     */
    offset?: MfaEnableNudgeOffset;
    className?: string;
    /** Renders the link, e.g. (link) => <Link {...link} />. Defaults to a plain <a>. */
    renderLink?: (link: MfaEnableNudgeLink) => ReactNode;
    /** The × button's accessible name; defaults to "Dismiss for today". */
    closeLabel?: string;
    /**
     * Still shows, but "Not today" and × only hide it for this page view and
     * never call onDismiss, e.g. while an admin impersonates the user.
     */
    disabled?: boolean;
};

const defaultLink = ({ href, className, children }: MfaEnableNudgeLink) => (
    <a href={href} className={className}>
        {children}
    </a>
);

const length = (value: number | string) => (typeof value === 'number' ? `${value}px` : value);

function browserTimezone(): string | undefined {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined;
    } catch {
        return undefined;
    }
}

// Static class names (Tailwind v3 and v4 only generate what they can read):
// the horizontal offset comes in through --mfa-nudge-x, set inline.
const HORIZONTAL = {
    left: 'sm:left-[var(--mfa-nudge-x)] sm:right-auto',
    right: 'sm:left-auto sm:right-[var(--mfa-nudge-x)]',
    center: 'sm:left-0 sm:right-0 sm:mx-auto',
};

export default function MfaEnableNudge({
    show,
    title,
    body,
    button,
    dismissLabel,
    settingsUrl,
    onDismiss,
    position = 'bottom-right',
    offset = 24,
    className = '',
    renderLink = defaultLink,
    closeLabel = 'Dismiss for today',
    disabled = false,
}: MfaEnableNudgeProps) {
    const id = useId();
    const [dismissed, setDismissed] = useState(false);

    // Once the server agrees (show turns false), a later show (the next day) shows it again.
    useEffect(() => {
        if (!show) setDismissed(false);
    }, [show]);

    if (!show || dismissed) return null;

    const [x, y] = Array.isArray(offset) ? offset : [offset, offset];
    const [vertical, horizontal] = position.split('-') as ['top' | 'bottom', keyof typeof HORIZONTAL];
    const style = { [vertical]: length(y), '--mfa-nudge-x': length(x) } as CSSProperties;

    const dismiss = () => {
        setDismissed(true);
        if (!disabled) onDismiss(browserTimezone());
    };

    return (
        <section
            role="region"
            aria-labelledby={`${id}-title`}
            aria-describedby={`${id}-body`}
            style={style}
            className={`fixed left-4 right-4 z-50 rounded-2xl border border-gray-200 bg-white p-4 shadow-lg shadow-gray-900/10 sm:w-80 dark:border-gray-800 dark:bg-gray-900 dark:shadow-black/40 ${HORIZONTAL[horizontal]} ${className}`}
        >
            <button
                type="button"
                onClick={dismiss}
                aria-label={closeLabel}
                className="absolute right-2 top-2 flex size-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-200"
            >
                <MfaIconClose size={16} />
            </button>
            <div className="flex gap-3 pr-6">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                    <MfaIconShieldLock size={20} />
                </div>
                <div className="min-w-0 space-y-1">
                    <p id={`${id}-title`} className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {title}
                    </p>
                    <p id={`${id}-body`} className="text-sm text-gray-500 dark:text-gray-400">
                        {body}
                    </p>
                </div>
            </div>
            <div className="mt-4 flex items-center justify-end gap-2">
                <button
                    type="button"
                    onClick={dismiss}
                    className="min-h-11 rounded-lg px-3 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                >
                    {dismissLabel}
                </button>
                {settingsUrl &&
                    renderLink({
                        href: settingsUrl,
                        className:
                            'inline-flex min-h-11 items-center rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white dark:bg-gray-100 dark:text-gray-900',
                        children: button,
                    })}
            </div>
        </section>
    );
}
