// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Sends (or resends) an email or SMS code, and counts down while the server's
// cooldown runs. Standalone: needs only React, imports no other MFA file, and
// knows nothing about Inertia or routes.
//
//     <MfaSendCodeButton onSend={send} processing={sending} retryAfter={retryAfter} sent={status === 'code-sent'} />
import { useEffect, useState } from 'react';

export type MfaSendCodeButtonProps = {
    onSend: () => void;
    processing?: boolean;
    /** Seconds until the server allows another send; a new value restarts the countdown. */
    retryAfter?: number | null;
    /** A code was just sent: shows a notice and offers "Send a new code". */
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

const formatWait = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

export default function MfaSendCodeButton({ onSend, processing = false, retryAfter = null, sent = false, error = null }: MfaSendCodeButtonProps) {
    const wait = useCountdown(retryAfter);

    return (
        <div className="space-y-2">
            {sent && (
                <p role="status" className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">
                    Code sent. It may take a moment to arrive.
                </p>
            )}
            <button
                type="button"
                disabled={processing || wait > 0}
                onClick={onSend}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300"
            >
                {wait > 0 ? `Resend in ${formatWait(wait)}` : sent ? 'Send a new code' : 'Send code'}
            </button>
            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
