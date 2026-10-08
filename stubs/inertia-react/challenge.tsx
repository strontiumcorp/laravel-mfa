// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely,
// or wrap it in your app's guest layout.
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

type Factor = {
    id: number;
    type: 'totp' | 'email' | 'sms';
    type_label: string;
    label: string | null;
    destination: string | null;
};

type Props = {
    factors: Factor[];
    defaultFactorId: number | null;
    hasRecoveryCodes: boolean;
    status: string | null;
    retryAfter: number | null;
    urls: { send: string; verify: string; recover: string; logout: string | null };
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

export default function MfaChallenge({ factors, defaultFactorId, hasRecoveryCodes, status, retryAfter, urls }: Props) {
    const wait = useCountdown(retryAfter);
    const [factorId, setFactorId] = useState<number | null>(defaultFactorId);
    const [useRecovery, setUseRecovery] = useState(false);
    const factor = factors.find((f) => f.id === factorId) ?? null;
    const delivered = factor?.type === 'email' || factor?.type === 'sms';
    const logoutUrl = urls.logout;

    // Form values must be serialisable scalars (no null) for Inertia's typing.
    const verify = useForm({ factor_id: String(factorId ?? ''), code: '' });
    const send = useForm({ factor_id: String(factorId ?? '') });
    const recover = useForm({ code: '' });
    // The send endpoint reports failures (cooldown, delivery) under "code".
    const sendError = (send.errors as Partial<Record<string, string>>).code;

    const selectFactor = (id: number) => {
        setFactorId(id);
        verify.setData({ factor_id: String(id), code: '' });
        send.setData({ factor_id: String(id) });
        verify.clearErrors();
    };

    const submitCode = (e: FormEvent) => {
        e.preventDefault();
        verify.post(urls.verify, { preserveScroll: true, onError: () => verify.setData('code', '') });
    };

    const submitRecovery = (e: FormEvent) => {
        e.preventDefault();
        recover.post(urls.recover, { preserveScroll: true });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-950">
            <Head title="Verify it's you" />

            <div className="w-full max-w-sm space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div className="space-y-1">
                    <h1 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Verify it's you</h1>
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        {useRecovery
                            ? 'Enter one of your recovery codes.'
                            : factor?.type === 'totp'
                              ? 'Enter the 6-digit code from your authenticator app.'
                              : `We'll send a code to ${factor?.destination ?? 'you'}.`}
                    </p>
                </div>

                {status === 'code-sent' && (
                    <p className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">
                        Code sent. It may take a moment to arrive.
                    </p>
                )}

                {!useRecovery && factors.length > 1 && (
                    <div className="flex flex-wrap gap-2">
                        {factors.map((f) => (
                            <button
                                key={f.id}
                                type="button"
                                onClick={() => selectFactor(f.id)}
                                className={`rounded-md border px-3 py-1.5 text-xs font-medium ${
                                    f.id === factorId
                                        ? 'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900'
                                        : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'
                                }`}
                            >
                                {f.label ?? f.type_label}
                            </button>
                        ))}
                    </div>
                )}

                {useRecovery ? (
                    <form onSubmit={submitRecovery} className="space-y-3">
                        <input
                            value={recover.data.code}
                            onChange={(e) => recover.setData('code', e.target.value)}
                            placeholder="xxxxx-xxxxx"
                            autoComplete="off"
                            autoFocus
                            className="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                        />
                        {recover.errors.code && <p className="text-sm text-red-600">{recover.errors.code}</p>}
                        <button disabled={recover.processing} className="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900">
                            Verify
                        </button>
                    </form>
                ) : (
                    <form onSubmit={submitCode} className="space-y-3">
                        {delivered && (
                            <button
                                type="button"
                                disabled={send.processing || wait > 0}
                                onClick={() => send.post(urls.send, { preserveScroll: true })}
                                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300"
                            >
                                {wait > 0 ? `Resend in ${formatWait(wait)}` : status === 'code-sent' ? 'Send a new code' : 'Send code'}
                            </button>
                        )}
                        {sendError && <p className="text-sm text-red-600">{sendError}</p>}

                        <input
                            value={verify.data.code}
                            onChange={(e) => verify.setData('code', e.target.value.replace(/\D/g, '').slice(0, 10))}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            placeholder="123456"
                            autoFocus
                            className="w-full rounded-md border border-gray-300 px-3 py-2 text-center font-mono text-lg tracking-[0.4em] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                        />
                        {verify.errors.code && <p className="text-sm text-red-600">{verify.errors.code}</p>}

                        <button
                            disabled={verify.processing || verify.data.code.length < 4}
                            className="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                        >
                            Verify
                        </button>
                    </form>
                )}

                <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    {hasRecoveryCodes ? (
                        <button type="button" onClick={() => setUseRecovery(!useRecovery)} className="underline">
                            {useRecovery ? 'Use a verification code' : 'Use a recovery code'}
                        </button>
                    ) : (
                        <span />
                    )}
                    {logoutUrl && (
                        <button type="button" onClick={() => router.post(logoutUrl)} className="underline">
                            Sign out
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
