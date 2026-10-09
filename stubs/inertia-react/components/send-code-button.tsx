// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The "Didn't get it? Resend in 0:58" line under an email or SMS code input
// ("You can get a new code in 0:58" while none is out): a link-style button
// that sends (or resends) a code, and counts down while the server's cooldown
// runs. Standalone: needs only React, imports no other MFA file, and
// knows nothing about Inertia or routes.
//
//     <MfaSendCodeButton onSend={send} processing={sending} retryAfter={retryAfter} sent={status === 'code-sent'} />
import { useEffect, useState } from 'react';

export type MfaSendCodeButtonProps = {
    onSend: () => void;
    processing?: boolean;
    /** Seconds until the server allows another send; a new value restarts the countdown (hours shown as h:mm:ss). */
    retryAfter?: number | null;
    /** A code is out: offers "Didn't get it? Send a new code" (else "Send code"). */
    sent?: boolean;
    error?: string | null;
};

/** Seconds left until another code can be requested; ticks down to 0. */
function useCountdown(seconds: number | null): number {
    const [left, setLeft] = useState(seconds ?? 0);

    // Each new response (send, cooldown error) restarts the countdown.
    useEffect(() => setLeft(seconds ?? 0), [seconds]);

    useEffect(() => {
        if (left <= 0) return;
        const timer = setTimeout(() => setLeft((s) => s - 1), 1000);
        return () => clearTimeout(timer);
    }, [left]);

    return left;
}

const pad = (n: number) => String(n).padStart(2, '0');
/** "0:58", "14:30", or "5:00:00" from an hour (e.g. a daily cap). */
const formatWait = (s: number) => (s >= 3600 ? `${Math.floor(s / 3600)}:${pad(Math.floor(s / 60) % 60)}:${pad(s % 60)}` : `${Math.floor(s / 60)}:${pad(s % 60)}`);

export default function MfaSendCodeButton({ onSend, processing = false, retryAfter = null, sent = false, error = null }: MfaSendCodeButtonProps) {
    const wait = useCountdown(retryAfter);

    return (
        <div className="space-y-1 text-center">
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {sent && !processing && "Didn't get it? "}
                <button
                    type="button"
                    disabled={processing || wait > 0}
                    onClick={onSend}
                    className="inline-flex min-h-11 items-center font-medium text-gray-900 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-900 disabled:text-gray-500 disabled:no-underline dark:text-gray-100 dark:decoration-gray-600 dark:hover:decoration-gray-100 dark:disabled:text-gray-400"
                >
                    {processing
                        ? 'Sending…'
                        : wait > 0
                          ? sent
                              ? `Resend in ${formatWait(wait)}`
                              : `You can get a new code in ${formatWait(wait)}`
                          : sent
                            ? 'Send a new code'
                            : 'Send code'}
                </button>
            </p>
            {error && (
                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </div>
    );
}
