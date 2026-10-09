// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Asks for the account password before a change to two-factor settings
// (routes.password_confirmation), right on the settings page. Standalone:
// needs only React, imports no other MFA file, and knows nothing about
// Inertia or routes.
//
//     <MfaPasswordConfirmForm onConfirm={(password) => confirm(password)} onCancel={cancel} processing={processing} error={errors.password} retryAfter={passwordRetryAfter} />
import { useEffect, useRef, useState } from 'react';

export type MfaPasswordConfirmFormProps = {
    /** Called with the password as typed. */
    onConfirm: (password: string) => void;
    /** Shows a "Cancel" button when given. */
    onCancel?: () => void;
    processing?: boolean;
    error?: string | null;
    /** Seconds until another attempt is allowed (too many wrong passwords); a new value restarts the countdown. */
    retryAfter?: number | null;
    className?: string;
};

/** Seconds left until another attempt is allowed; ticks down to 0. */
function useCountdown(seconds: number | null): number {
    const [left, setLeft] = useState(seconds ?? 0);

    // Each new response restarts the countdown.
    useEffect(() => setLeft(seconds ?? 0), [seconds]);

    useEffect(() => {
        if (left <= 0) return;
        const timer = setTimeout(() => setLeft((s) => s - 1), 1000);
        return () => clearTimeout(timer);
    }, [left]);

    return left;
}

// The daily cap waits up to 24 hours: show those in hours.
const formatWait = (s: number) => (s >= 3600 ? `${Math.ceil(s / 3600)} h` : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`);

export default function MfaPasswordConfirmForm({ onConfirm, onCancel, processing = false, error = null, retryAfter = null, className = '' }: MfaPasswordConfirmFormProps) {
    const [password, setPassword] = useState('');
    const wait = useCountdown(retryAfter);

    // After a failed attempt, clear the input for the next try.
    const wasProcessing = useRef(processing);
    useEffect(() => {
        if (wasProcessing.current && !processing && error) setPassword('');
        wasProcessing.current = processing;
    }, [processing, error]);

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        onConfirm(password);
    };

    return (
        <section aria-labelledby="mfa-confirm-password" className={`space-y-3 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900 dark:bg-blue-950/30 ${className}`}>
            <div className="space-y-1">
                <h2 id="mfa-confirm-password" className="text-sm font-medium text-gray-900 dark:text-gray-100">
                    Confirm your password
                </h2>
                <p className="text-sm text-gray-600 dark:text-gray-400">For your security, enter your password to change two-factor settings.</p>
            </div>

            <form onSubmit={submit} className="flex flex-wrap gap-2">
                <input
                    type="password"
                    aria-label="Password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    autoComplete="current-password"
                    autoFocus
                    className="min-h-11 min-w-0 flex-1 rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                />
                <button
                    type="submit"
                    disabled={processing || password === '' || wait > 0}
                    className="min-h-11 rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                >
                    {wait > 0 ? `Try again in ${formatWait(wait)}` : 'Confirm'}
                </button>
                {onCancel && (
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={processing}
                        className="min-h-11 rounded-lg px-3 text-sm font-medium text-gray-600 disabled:opacity-50 dark:text-gray-400"
                    >
                        Cancel
                    </button>
                )}
            </form>
            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </section>
    );
}
