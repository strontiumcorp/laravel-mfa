// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Finishes adding an authenticator app: shows the QR code and the key, then
// confirms with a code from the app. Standalone: needs only React, imports
// no other MFA file, and knows nothing about Inertia or routes.
//
//     <MfaTotpSetup secret={p.secret} qrSvg={p.qr_svg} onConfirm={(code) => confirm(p.id, code)} processing={processing} error={errors.code} />
import { useEffect, useRef, useState } from 'react';

export type MfaTotpSetupProps = {
    /** The base32 key, for typing in by hand. */
    secret: string;
    /**
     * The QR code as SVG markup. It is rendered as HTML, so pass only the
     * server's own output (the settings page's `qr_svg`), never user input.
     */
    qrSvg?: string | null;
    /** Called with the digits the user entered. */
    onConfirm: (code: string) => void;
    processing?: boolean;
    error?: string | null;
    /** Heading label; defaults to "authenticator app". */
    label?: string;
};

export default function MfaTotpSetup({ secret, qrSvg = null, onConfirm, processing = false, error = null, label = 'Authenticator app' }: MfaTotpSetupProps) {
    const [code, setCode] = useState('');

    // After a failed attempt, clear the input for the next try.
    const wasProcessing = useRef(processing);
    useEffect(() => {
        if (wasProcessing.current && !processing && error) setCode('');
        wasProcessing.current = processing;
    }, [processing, error]);

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        onConfirm(code);
    };

    return (
        <section className="space-y-4 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
            <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Finish setting up {label.toLowerCase()}</h2>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                {qrSvg && (
                    <div
                        role="img"
                        aria-label="QR code for your authenticator app"
                        className="w-44 shrink-0 rounded bg-white p-2"
                        dangerouslySetInnerHTML={{ __html: qrSvg }}
                    />
                )}
                <div className="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <p>Scan with Google Authenticator, 1Password, Authy or similar. Or enter this key:</p>
                    <code className="block break-all rounded bg-white px-2 py-1 font-mono text-xs text-gray-900 dark:bg-gray-900 dark:text-gray-100">{secret}</code>
                </div>
            </div>

            <form onSubmit={submit} className="flex gap-2">
                <input
                    aria-label="Code from your authenticator app"
                    value={code}
                    onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 10))}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="123456"
                    className="w-40 rounded-md border border-gray-300 px-3 py-2 text-center font-mono tracking-widest dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                />
                <button
                    type="submit"
                    disabled={processing || code.length < 4}
                    className="rounded-md bg-gray-900 px-3 py-2 text-sm text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
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
