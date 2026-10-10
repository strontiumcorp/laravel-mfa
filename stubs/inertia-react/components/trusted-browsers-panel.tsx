// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Trusted browsers: the browsers where the user ticked "Don't ask again on this
// browser" at sign-in, so they skip the code (never the password) until the
// trust runs out. Lists each one (this browser marked), with "Forget" per
// browser and "Forget all" (which asks inside the card first). Needs only React and ./icons; imports no other
// component and knows nothing about Inertia or routes. Dates are formatted in
// the browser's locale.
//
//     <MfaTrustedBrowsersPanel browsers={trustedBrowsers} onForget={forget} onForgetAll={forgetAll} forgettingId={id} />
import { useRef, useState } from 'react';
import { MfaIconBrowser } from './icons';

export type MfaTrustedBrowser = {
    id: number;
    /** E.g. "Chrome on Mac"; null when the browser wasn't recognised. */
    label: string | null;
    /** ISO 8601. */
    created_at: string | null;
    /** ISO 8601; null until it has skipped a challenge. */
    last_used_at: string | null;
    /** ISO 8601: when the browser has to verify again. */
    expires_at: string;
    /** The browser this page is open in. */
    current: boolean;
};

export type MfaTrustedBrowsersPanelProps = {
    /** Newest first; [] shows a short note instead of the list. */
    browsers: MfaTrustedBrowser[];
    /** Called with the browser's id when its "Forget" is clicked. */
    onForget: (id: number) => void;
    /** Shows "Forget all" (when there is more than one browser); called once the user confirms it in the card. */
    onForgetAll?: () => void;
    /** The browser being forgotten right now: its button says so, and every button waits. */
    forgettingId?: number | null;
    /** "Forget all" is running: every button waits. */
    forgettingAll?: boolean;
    /** Added to the card's classes. */
    className?: string;
};

const BUTTON =
    'min-h-11 shrink-0 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-900 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800';

/** "Oct 30, 2026" in the browser's locale; the ISO date when Intl isn't there. */
function formatDate(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    try {
        return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(date);
    } catch {
        return date.toISOString().slice(0, 10);
    }
}

/** "3 hours ago", "yesterday", … within a week; a date before that (or without Intl.RelativeTimeFormat). */
function formatRelative(iso: string): string {
    const date = new Date(iso);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    if (Number.isNaN(seconds) || seconds < -7 * 86400 || seconds > 60) return formatDate(iso);
    if (seconds > -60) return 'just now';
    try {
        const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
        if (seconds > -3600) return rtf.format(Math.round(seconds / 60), 'minute');
        if (seconds > -86400) return rtf.format(Math.round(seconds / 3600), 'hour');

        return rtf.format(Math.round(seconds / 86400), 'day');
    } catch {
        return formatDate(iso);
    }
}

export default function MfaTrustedBrowsersPanel({ browsers, onForget, onForgetAll, forgettingId = null, forgettingAll = false, className = '' }: MfaTrustedBrowsersPanelProps) {
    const busy = forgettingAll || forgettingId !== null;
    // "Forget all" asks first, inside the card.
    const [asking, setAsking] = useState(false);
    const forgetAllButton = useRef<HTMLButtonElement>(null);
    const cancel = () => {
        setAsking(false);
        // After the re-render that enables it again.
        setTimeout(() => forgetAllButton.current?.focus());
    };

    return (
        <div className={`rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 ${className}`}>
            <div className="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-6 sm:py-5">
                <p className="min-w-0 flex-1 basis-60 text-sm text-gray-600 dark:text-gray-400">These browsers skip the code at sign-in. Your password is still needed.</p>
                {onForgetAll && browsers.length > 1 && (
                    <button
                        ref={forgetAllButton}
                        type="button"
                        onClick={() => setAsking(true)}
                        disabled={busy || asking}
                        aria-busy={forgettingAll || undefined}
                        className={`${BUTTON} w-full sm:w-auto`}
                    >
                        {forgettingAll ? 'Forgetting…' : 'Forget all'}
                    </button>
                )}
            </div>

            {asking && onForgetAll && browsers.length > 1 && (
                <div
                    role="group"
                    aria-label="Forget all trusted browsers?"
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') cancel();
                    }}
                    className="flex flex-wrap items-center gap-x-4 gap-y-3 border-t border-red-100 bg-red-50 px-4 py-4 sm:px-6 dark:border-red-900/60 dark:bg-red-950/40"
                >
                    <div className="min-w-0 flex-1 basis-60 space-y-0.5">
                        <p className="text-sm font-semibold text-red-900 dark:text-red-200">Forget all trusted browsers?</p>
                        <p className="text-sm text-red-800 dark:text-red-300">
                            {browsers.some((b) => b.current) ? 'Each of them, this one too, asks for a code at the next sign-in.' : 'Each of them asks for a code at the next sign-in.'}
                        </p>
                    </div>
                    {/* Phones: Cancel and Forget all side by side, Forget all on the right. */}
                    <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                        <button type="button" autoFocus onClick={cancel} className={BUTTON}>
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setAsking(false);
                                onForgetAll();
                            }}
                            className="min-h-11 rounded-lg bg-red-600 px-5 text-sm font-semibold text-white dark:bg-red-600 dark:text-white"
                        >
                            Forget all
                        </button>
                    </div>
                </div>
            )}

            {browsers.length === 0 ? (
                <p className="border-t border-gray-200 px-4 py-4 text-sm text-gray-500 sm:px-6 dark:border-gray-800 dark:text-gray-400">
                    None yet. When you sign in, you can choose not to be asked again on that browser.
                </p>
            ) : (
                <ul aria-label="Trusted browsers" className="divide-y divide-gray-200 border-t border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    {browsers.map((b) => {
                        const name = b.label ?? 'Unknown browser';
                        const used = b.last_used_at ? `Last used ${formatRelative(b.last_used_at)}` : b.created_at ? `Added ${formatDate(b.created_at)}` : null;
                        const forgetting = forgettingId === b.id;

                        return (
                            <li key={b.id} className="flex items-center gap-3 px-4 py-3 sm:gap-4 sm:px-6">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                    <MfaIconBrowser size={20} />
                                </span>
                                <div className="min-w-0 grow">
                                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span className="text-sm font-medium text-gray-900 dark:text-gray-100">{name}</span>
                                        {b.current && (
                                            <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/50 dark:text-green-300">This browser</span>
                                        )}
                                    </p>
                                    <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                        {/* One line each on phones. */}
                                        {used && (
                                            <span className="block sm:inline">
                                                {used}
                                                <span className="hidden sm:inline"> · </span>
                                            </span>
                                        )}
                                        <span className="block sm:inline">Trusted until {formatDate(b.expires_at)}</span>
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onForget(b.id)}
                                    disabled={busy}
                                    aria-busy={forgetting || undefined}
                                    aria-label={`Forget ${b.current ? 'this browser' : name}`}
                                    className={BUTTON}
                                >
                                    {forgetting ? 'Forgetting…' : 'Forget'}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
