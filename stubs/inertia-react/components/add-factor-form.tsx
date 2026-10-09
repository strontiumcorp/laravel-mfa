// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Starts enrolling a new method. An authenticator app starts at once; email
// and SMS first ask for the destination. Standalone: needs only React,
// imports no other MFA file, and knows nothing about Inertia or routes.
//
//     <MfaAddFactorForm types={availableTypes} onAdd={(type, destination) => store(type, destination)} processing={processing} error={errors.destination} />
import { useEffect, useRef, useState } from 'react';

export type MfaEnrollableType = 'totp' | 'email' | 'sms';

export type MfaAddFactorFormProps = {
    /** The types the user may add, with their display labels. Recommended ones are badged and listed first. */
    types: { type: MfaEnrollableType; label: string; recommended?: boolean }[];
    /**
     * Called with the type, and for email/SMS the destination as typed. An empty
     * email destination means "use the account email".
     */
    onAdd: (type: MfaEnrollableType, destination?: string) => void;
    processing?: boolean;
    error?: string | null;
};

export default function MfaAddFactorForm({ types, onAdd, processing = false, error = null }: MfaAddFactorFormProps) {
    const [adding, setAdding] = useState<Exclude<MfaEnrollableType, 'totp'> | null>(null);
    const [destination, setDestination] = useState('');

    const close = () => {
        setAdding(null);
        setDestination('');
    };

    // Close the destination form once a submission finishes without an error.
    const wasProcessing = useRef(processing);
    useEffect(() => {
        if (wasProcessing.current && !processing && !error) close();
        wasProcessing.current = processing;
    }, [processing, error]);

    const choose = (type: MfaEnrollableType) => {
        if (type === 'totp') return onAdd('totp');
        setAdding(type);
        setDestination('');
    };

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        if (adding) onAdd(adding, destination.trim());
    };

    if (types.length === 0) return null;

    // Stable: recommended first, otherwise in the given order.
    const ordered = [...types].sort((a, b) => Number(!!b.recommended) - Number(!!a.recommended));

    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Add a method</h2>
            <div className="flex flex-wrap gap-2">
                {ordered.map((t) => (
                    <button
                        key={t.type}
                        type="button"
                        disabled={processing}
                        aria-pressed={t.type === adding}
                        aria-label={t.recommended ? `${t.label} (recommended)` : undefined}
                        onClick={() => choose(t.type)}
                        className="inline-flex items-center gap-2 rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300"
                    >
                        {t.label}
                        {t.recommended && (
                            <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">
                                Recommended
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {adding && (
                <form onSubmit={submit} className="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <label htmlFor="mfa-add-destination" className="block text-sm text-gray-700 dark:text-gray-300">
                        {adding === 'sms' ? 'Phone number, with country code' : 'Email address (leave empty to use your account email)'}
                    </label>
                    <input
                        id="mfa-add-destination"
                        value={destination}
                        onChange={(e) => setDestination(e.target.value)}
                        type={adding === 'sms' ? 'tel' : 'email'}
                        placeholder={adding === 'sms' ? '+1 555 555 0100' : 'you@example.com'}
                        autoFocus
                        className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                    />
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={processing || (adding === 'sms' && destination.trim() === '')}
                            className="rounded-md bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                        >
                            Send code
                        </button>
                        <button type="button" onClick={close} className="px-3 py-1.5 text-sm text-gray-500">
                            Cancel
                        </button>
                    </div>
                </form>
            )}

            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </section>
    );
}
