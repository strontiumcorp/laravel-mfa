// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// "Still there?": a small floating card shown shortly before the session's
// idle timeout (config mfa.lifetime, a profile's `idle`) would ask for the
// sign-in code again. "Stay signed in" tells the app the user is here; it can
// never extend the fixed window, only the idle timeout. Once the timeout has
// passed it says so, and "Continue" reloads the page (the app then asks for
// the code and comes back here). Standalone: needs only React and ./icons,
// and knows nothing about Inertia or routes.
//
// In an Inertia app, useMfaIdleWarning() from the MFA pages' mfa-context.ts
// gives every prop, and renders nothing for users without an idle timeout:
//
//     import MfaIdleWarning from '@/components/vendor/laravel-mfa/idle-warning';
//     import { useMfaIdleWarning } from '@/pages/mfa/mfa-context';
//
//     <MfaIdleWarning {...useMfaIdleWarning()} />
import { useEffect, useId, useRef, useState } from 'react';
import { MfaIconShieldLock } from './icons';

export type MfaIdleWarningPosition = 'top-left' | 'top-center' | 'top-right' | 'bottom-left' | 'bottom-center' | 'bottom-right';

export type MfaIdleWarningProps = {
    /** ISO 8601: when the idle timeout ends. null renders nothing. */
    idleExpiresAt: string | null;
    /** "Stay signed in": resolves with the new deadline, or null if the session couldn't be kept (then it shows as ended). */
    onStay: () => Promise<string | null>;
    /**
     * Asked just before warning, for the deadline as the server sees it now
     * (activity in another tab moves it). Resolve null to warn anyway.
     */
    refresh?: () => Promise<string | null>;
    /** Once ended: "Continue". Defaults to reloading the page. */
    onContinue?: () => void;
    /** How long before the deadline it warns, in seconds; defaults to 120. */
    warnSeconds?: number;
    title?: string;
    /** ":countdown" becomes e.g. "1:45". */
    body?: string;
    stayLabel?: string;
    endedTitle?: string;
    endedBody?: string;
    continueLabel?: string;
    /** The corner or edge it floats at; defaults to top-center. Phones (below `sm`) get the full width. */
    position?: MfaIdleWarningPosition;
    className?: string;
};

// Static class names (Tailwind only generates what it can read).
const PLACEMENT: Record<MfaIdleWarningPosition, string> = {
    'top-left': 'top-4 sm:top-6 sm:left-6 sm:right-auto',
    'top-center': 'top-4 sm:top-6 sm:left-0 sm:right-0 sm:mx-auto',
    'top-right': 'top-4 sm:top-6 sm:left-auto sm:right-6',
    'bottom-left': 'bottom-4 sm:bottom-6 sm:left-6 sm:right-auto',
    'bottom-center': 'bottom-4 sm:bottom-6 sm:left-0 sm:right-0 sm:mx-auto',
    'bottom-right': 'bottom-4 sm:bottom-6 sm:left-auto sm:right-6',
};

// setTimeout's longest delay is about 24.8 days.
const MAX_DELAY = 2 ** 31 - 1;

function countdown(ms: number): string {
    const seconds = Math.max(0, Math.ceil(ms / 1000));

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

export default function MfaIdleWarning({
    idleExpiresAt,
    onStay,
    refresh,
    onContinue = () => window.location.reload(),
    warnSeconds = 120,
    title = 'Still there?',
    body = "For your security, you'll need your sign-in code again in :countdown unless you stay.",
    stayLabel = 'Stay signed in',
    endedTitle = "Verify it's you",
    endedBody = 'You were away for a while. Enter your sign-in code to continue.',
    continueLabel = 'Continue',
    position = 'top-center',
    className = '',
}: MfaIdleWarningProps) {
    const id = useId();
    const [deadline, setDeadline] = useState<number | null>(null);
    const [phase, setPhase] = useState<'idle' | 'warning' | 'ended'>('idle');
    const [, setTick] = useState(0);
    const [staying, setStaying] = useState(false);
    const latest = useRef<number | null>(null);

    // A new page (or a refresh elsewhere) brings a new deadline.
    useEffect(() => {
        const at = idleExpiresAt ? new Date(idleExpiresAt).getTime() : NaN;
        setDeadline(Number.isNaN(at) ? null : at);
        setPhase('idle');
    }, [idleExpiresAt]);

    latest.current = deadline;

    // Before the deadline: wait, then (after asking the server) warn. At it: ended.
    useEffect(() => {
        if (deadline === null) return;
        const warnAt = deadline - warnSeconds * 1000;
        let cancelled = false;
        const timers: ReturnType<typeof setTimeout>[] = [];

        if (phase === 'idle') {
            timers.push(
                setTimeout(async () => {
                    const fresh = refresh ? await refresh().catch(() => null) : null;
                    if (cancelled) return;
                    const at = fresh ? new Date(fresh).getTime() : NaN;
                    if (!Number.isNaN(at) && at > (latest.current ?? 0)) {
                        setDeadline(at); // another tab was active: wait again
                    } else {
                        setPhase('warning');
                    }
                }, Math.min(Math.max(0, warnAt - Date.now()), MAX_DELAY)),
            );
        }

        if (phase === 'warning') {
            const tick = setInterval(() => setTick((n) => n + 1), 1000);
            // At the deadline, ask once more: another tab may have kept the session alive.
            timers.push(
                setTimeout(async () => {
                    const fresh = refresh ? await refresh().catch(() => null) : null;
                    if (cancelled) return;
                    const at = fresh ? new Date(fresh).getTime() : NaN;
                    if (!Number.isNaN(at) && at > Date.now()) {
                        setDeadline(at);
                        setPhase('idle');
                    } else {
                        setPhase('ended');
                    }
                }, Math.max(0, deadline - Date.now())),
            );

            return () => {
                cancelled = true;
                clearInterval(tick);
                timers.forEach(clearTimeout);
            };
        }

        return () => {
            cancelled = true;
            timers.forEach(clearTimeout);
        };
    }, [deadline, phase, warnSeconds, refresh]);

    if (deadline === null || phase === 'idle') return null;

    const ended = phase === 'ended';

    const stay = async () => {
        setStaying(true);
        const next = await onStay().catch(() => null);
        setStaying(false);
        const at = next ? new Date(next).getTime() : NaN;
        if (Number.isNaN(at) || at <= Date.now()) {
            setPhase('ended');
        } else {
            setDeadline(at);
            setPhase('idle');
        }
    };

    return (
        <section
            role="region"
            aria-labelledby={`${id}-title`}
            aria-describedby={`${id}-body`}
            aria-live="polite"
            className={`fixed left-4 right-4 z-50 rounded-2xl border border-gray-200 bg-white p-4 shadow-lg shadow-gray-900/10 sm:w-80 dark:border-gray-800 dark:bg-gray-900 dark:shadow-black/40 ${PLACEMENT[position]} ${className}`}
        >
            <div className="flex gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                    <MfaIconShieldLock size={20} />
                </div>
                <div className="min-w-0 space-y-1">
                    <p id={`${id}-title`} className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {ended ? endedTitle : title}
                    </p>
                    <p id={`${id}-body`} className="text-sm text-gray-500 dark:text-gray-400">
                        {ended ? endedBody : body.replace(/:countdown/g, countdown(deadline - Date.now()))}
                    </p>
                </div>
            </div>
            <div className="mt-4 flex items-center justify-end gap-2">
                {ended ? (
                    <button
                        type="button"
                        onClick={onContinue}
                        className="inline-flex min-h-11 items-center rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white dark:bg-gray-100 dark:text-gray-900"
                    >
                        {continueLabel}
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={stay}
                        disabled={staying}
                        className="inline-flex min-h-11 items-center rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-60 dark:bg-gray-100 dark:text-gray-900"
                    >
                        {stayLabel}
                    </button>
                )}
            </div>
        </section>
    );
}
