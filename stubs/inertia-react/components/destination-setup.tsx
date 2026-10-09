// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Finishes adding an email or SMS method: confirms the code that was sent,
// with a resend link that counts down the server's cooldown. Standalone:
// needs only React, imports no other MFA file, and knows nothing about
// Inertia or routes.
//
//     <MfaDestinationSetup label={p.type_label} destination={p.destination} onConfirm={(code) => confirm(p.id, code)} onResend={() => resend(p.id)} retryAfter={retryAfter} />
import { useEffect, useRef, useState } from 'react';

export type MfaDestinationSetupProps = {
    /** The method's display label, e.g. "Email". */
    label: string;
    /** Masked, e.g. "j***@example.com". */
    destination: string | null;
    /** Called with the digits the user entered. */
    onConfirm: (code: string) => void;
    onResend: () => void;
    /** Confirming is in flight. */
    processing?: boolean;
    /** Resending is in flight. */
    resending?: boolean;
    /** Seconds until the server allows another send; a new value restarts the countdown. */
    retryAfter?: number | null;
    /** A code was just resent. */
    sent?: boolean;
    /** From confirming or resending. */
    error?: string | null;
    /**
     * Its own box and heading (default). false when it sits inside a card that
     * already names the method, e.g. MfaFactorCards' setups.
     */
    framed?: boolean;
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

export default function MfaDestinationSetup({
    label,
    destination,
    onConfirm,
    onResend,
    processing = false,
    resending = false,
    retryAfter = null,
    sent = false,
    error = null,
    framed = true,
}: MfaDestinationSetupProps) {
    const wait = useCountdown(retryAfter);
    const [code, setCode] = useState('');

    // After a failed attempt, keep the code so the user sees what they typed,
    // and select it so typing straight away replaces it.
    const input = useRef<HTMLInputElement>(null);
    const wasProcessing = useRef(processing);
    useEffect(() => {
        if (wasProcessing.current && !processing && error) {
            input.current?.focus();
            input.current?.select();
        }
        wasProcessing.current = processing;
    }, [processing, error]);

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        onConfirm(code);
    };

    return (
        <section
            aria-label={framed ? undefined : `Finish setting up ${label.toLowerCase()}`}
            className={framed ? 'space-y-4 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900 dark:bg-blue-950/30' : 'space-y-4'}
        >
            {framed && <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Finish setting up {label.toLowerCase()}</h2>}

            <p className="text-sm text-gray-600 dark:text-gray-400">
                We sent a code to {destination ?? 'you'}.{' '}
                <button type="button" disabled={resending || wait > 0} onClick={onResend} className="underline disabled:no-underline disabled:opacity-60">
                    {wait > 0 ? `Resend in ${formatWait(wait)}` : 'Resend'}
                </button>
                {sent && <span role="status"> · Sent!</span>}
            </p>

            <form onSubmit={submit} className="flex gap-2">
                <input
                    ref={input}
                    aria-label="Verification code"
                    value={code}
                    onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 10))}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="123456"
                    className="min-h-11 w-40 rounded-lg border border-gray-300 px-3 text-center font-mono tracking-widest dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                />
                <button
                    type="submit"
                    disabled={processing || code.length < 4}
                    className="min-h-11 rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                >
                    Confirm
                </button>
            </form>
            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </section>
    );
}
