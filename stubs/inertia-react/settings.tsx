// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely,
// or render it inside your app's settings layout.
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

type FactorType = 'totp' | 'email' | 'sms';

type Factor = {
    id: number;
    type: FactorType;
    type_label: string;
    label: string | null;
    destination: string | null;
    confirmed_at: string | null;
    last_used_at: string | null;
};

type Pending = Factor & { secret?: string; qr_svg?: string; otpauth_url?: string };

type Props = {
    factors: Factor[];
    pending: Pending[];
    availableTypes: { type: FactorType; label: string }[];
    recoveryCodesRemaining: number;
    recoveryCodes: string[] | null;
    retryAfter: number | null;
    mustEnroll: boolean;
    status: string | null;
    urls: { store: string; confirm: string; resend: string; destroy: string; recoveryCodes: string };
};

const withId = (url: string, id: number) => url.replace('__ID__', String(id));

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

export default function MfaSettings(props: Props) {
    const { factors, pending, availableTypes, recoveryCodesRemaining, recoveryCodes, mustEnroll, status, retryAfter, urls } = props;
    const wait = useCountdown(retryAfter);
    const [adding, setAdding] = useState<FactorType | null>(null);

    return (
        <div className="mx-auto max-w-2xl space-y-8 px-4 py-10">
            <Head title="Two-factor authentication" />

            <header className="space-y-1">
                <h1 className="text-xl font-semibold text-gray-900 dark:text-gray-100">Two-factor authentication</h1>
                <p className="text-sm text-gray-500 dark:text-gray-400">Add a second step to sign in, so a stolen password isn't enough.</p>
                {mustEnroll && (
                    <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        Your account requires two-factor authentication. Set up a method to continue.
                    </p>
                )}
            </header>

            {recoveryCodes && <RecoveryCodesPanel codes={recoveryCodes} />}

            <section className="space-y-3">
                <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Your methods</h2>
                {factors.length === 0 && <p className="text-sm text-gray-500">No methods set up yet.</p>}
                {factors.map((f) => (
                    <div key={f.id} className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                        <div>
                            <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{f.label ?? f.type_label}</p>
                            <p className="text-xs text-gray-500">
                                {f.destination ?? f.type_label}
                                {f.last_used_at && ` · last used ${new Date(f.last_used_at).toLocaleDateString()}`}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => confirm('Remove this method?') && router.delete(withId(urls.destroy, f.id), { preserveScroll: true })}
                            className="text-xs font-medium text-red-600"
                        >
                            Remove
                        </button>
                    </div>
                ))}
            </section>

            {pending.map((p) => (
                <PendingSetup key={p.id} factor={p} urls={urls} status={status} wait={wait} />
            ))}

            <section className="space-y-3">
                <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Add a method</h2>
                <div className="flex flex-wrap gap-2">
                    {availableTypes.map((t) => (
                        <button
                            key={t.type}
                            type="button"
                            onClick={() => (t.type === 'totp' ? router.post(urls.store, { type: 'totp' }, { preserveScroll: true }) : setAdding(t.type))}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300"
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                {adding && <AddDestination type={adding} url={urls.store} onDone={() => setAdding(null)} />}
            </section>

            {factors.length > 0 && (
                <section className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                    <div>
                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100">Recovery codes</p>
                        <p className="text-xs text-gray-500">{recoveryCodesRemaining} remaining. Use one if you lose access to your methods.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => confirm('Generate new codes? Your old codes will stop working.') && router.post(urls.recoveryCodes, {}, { preserveScroll: true })}
                        className="text-xs font-medium text-gray-700 underline dark:text-gray-300"
                    >
                        Regenerate
                    </button>
                </section>
            )}
        </div>
    );
}

function AddDestination({ type, url, onDone }: { type: FactorType; url: string; onDone: () => void }) {
    const form = useForm({ type, destination: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
            <label className="block text-sm text-gray-700 dark:text-gray-300">
                {type === 'sms' ? 'Phone number, with country code' : 'Email address (leave empty to use your account email)'}
            </label>
            <input
                value={form.data.destination}
                onChange={(e) => form.setData('destination', e.target.value)}
                type={type === 'sms' ? 'tel' : 'email'}
                placeholder={type === 'sms' ? '+1 555 555 0100' : 'you@example.com'}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
            />
            {(form.errors.destination || form.errors.type) && <p className="text-sm text-red-600">{form.errors.destination ?? form.errors.type}</p>}
            <div className="flex gap-2">
                <button disabled={form.processing} className="rounded-md bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900">
                    Send code
                </button>
                <button type="button" onClick={onDone} className="px-3 py-1.5 text-sm text-gray-500">
                    Cancel
                </button>
            </div>
        </form>
    );
}

function PendingSetup({ factor, urls, status, wait }: { factor: Pending; urls: Props['urls']; status: string | null; wait: number }) {
    const form = useForm({ code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(withId(urls.confirm, factor.id), { preserveScroll: true, onError: () => form.setData('code', '') });
    };

    return (
        <section className="space-y-4 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
            <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Finish setting up {factor.type_label.toLowerCase()}</h2>

            {factor.type === 'totp' && factor.qr_svg ? (
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    {/* Server-generated SVG (bacon/bacon-qr-code), not user input. */}
                    <div className="w-44 shrink-0 rounded bg-white p-2" dangerouslySetInnerHTML={{ __html: factor.qr_svg }} />
                    <div className="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                        <p>Scan with Google Authenticator, 1Password, Authy or similar. Or enter this key:</p>
                        <code className="block break-all rounded bg-white px-2 py-1 font-mono text-xs text-gray-900 dark:bg-gray-900 dark:text-gray-100">{factor.secret}</code>
                    </div>
                </div>
            ) : (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    We sent a code to {factor.destination}.{' '}
                    <button
                        type="button"
                        disabled={wait > 0}
                        onClick={() => router.post(withId(urls.resend, factor.id), {}, { preserveScroll: true })}
                        className="underline disabled:no-underline disabled:opacity-60"
                    >
                        {wait > 0 ? `Resend in ${formatWait(wait)}` : 'Resend'}
                    </button>
                    {status === 'code-sent' && ' · Sent!'}
                </p>
            )}

            <form onSubmit={submit} className="flex gap-2">
                <input
                    value={form.data.code}
                    onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, '').slice(0, 10))}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="123456"
                    className="w-40 rounded-md border border-gray-300 px-3 py-2 text-center font-mono tracking-widest dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                />
                <button disabled={form.processing || form.data.code.length < 4} className="rounded-md bg-gray-900 px-3 py-2 text-sm text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900">
                    Confirm
                </button>
            </form>
            {form.errors.code && <p className="text-sm text-red-600">{form.errors.code}</p>}
        </section>
    );
}

function RecoveryCodesPanel({ codes }: { codes: string[] }) {
    const [copied, setCopied] = useState(false);

    return (
        <section className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
            <div>
                <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Save your recovery codes</h2>
                <p className="text-xs text-gray-600 dark:text-gray-400">Each code works once. Store them somewhere safe — they won't be shown again.</p>
            </div>
            <ul className="grid grid-cols-2 gap-1 font-mono text-sm text-gray-900 dark:text-gray-100">
                {codes.map((c) => (
                    <li key={c}>{c}</li>
                ))}
            </ul>
            <button
                type="button"
                onClick={() => navigator.clipboard.writeText(codes.join('\n')).then(() => setCopied(true))}
                className="text-xs font-medium underline"
            >
                {copied ? 'Copied' : 'Copy all'}
            </button>
        </section>
    );
}
