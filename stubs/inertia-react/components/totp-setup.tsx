// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Finishes adding an authenticator app: shows the QR code and the key (with a
// Copy button, and on phones an "Open in authenticator app" link, since a phone
// can't scan its own screen), then confirms with a code from the app.
// Standalone: needs only React, imports no other MFA file, and knows nothing
// about Inertia or routes.
//
//     <MfaTotpSetup secret={p.secret} qrSvg={p.qr_svg} otpauthUrl={p.otpauth_url} onConfirm={(code) => confirm(p.id, code)} processing={processing} error={errors.code} />
import { useEffect, useRef, useState } from 'react';

export type MfaTotpSetupProps = {
    /** The base32 key, for typing in by hand. */
    secret: string;
    /**
     * The QR code as SVG markup. It is rendered as HTML, so pass only the
     * server's own output (the settings page's `qr_svg`), never user input.
     */
    qrSvg?: string | null;
    /**
     * The otpauth:// link (the settings page's `otpauth_url`). On phones it shows
     * as an "Open in authenticator app" button: the app registered for the link
     * adds the account without scanning.
     */
    otpauthUrl?: string | null;
    /** Called with the digits the user entered. */
    onConfirm: (code: string) => void;
    processing?: boolean;
    error?: string | null;
    /** Heading label; defaults to "authenticator app". */
    label?: string;
    /**
     * Its own box and heading (default). false when it sits inside a card that
     * already names the method, e.g. MfaFactorCards' setups.
     */
    framed?: boolean;
};

// Groups of four are easier to read and type; copying gives the key without spaces.
const grouped = (key: string) => key.replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ');

export default function MfaTotpSetup({
    secret,
    qrSvg = null,
    otpauthUrl = null,
    onConfirm,
    processing = false,
    error = null,
    label = 'Authenticator app',
    framed = true,
}: MfaTotpSetupProps) {
    const [code, setCode] = useState('');
    const [copied, setCopied] = useState(false);

    const copy = () =>
        navigator.clipboard?.writeText(secret.replace(/\s+/g, '')).then(
            () => setCopied(true),
            () => setCopied(false),
        );

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

            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                {otpauthUrl && (
                    <a
                        href={otpauthUrl}
                        className="flex min-h-11 items-center justify-center rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white sm:hidden dark:bg-gray-100 dark:text-gray-900"
                    >
                        Open in authenticator app
                    </a>
                )}
                {qrSvg && (
                    <div
                        role="img"
                        aria-label="QR code for your authenticator app"
                        className="w-40 shrink-0 self-center rounded-lg bg-white p-2 ring-1 ring-gray-200 sm:w-44 sm:self-auto dark:ring-gray-700"
                        dangerouslySetInnerHTML={{ __html: qrSvg }}
                    />
                )}
                <div className="min-w-0 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <p>
                        {otpauthUrl ? <span className="sm:hidden">Or scan the QR code from another device, or enter this key:</span> : null}
                        <span className={otpauthUrl ? 'hidden sm:inline' : undefined}>Scan with Google Authenticator, 1Password, Authy or similar. Or enter this key:</span>
                    </p>
                    <div className="flex items-center gap-2">
                        <code aria-label="Setup key" className="min-w-0 break-words rounded-md bg-gray-100 px-2.5 py-1.5 font-mono text-sm tracking-wide text-gray-900 dark:bg-gray-800 dark:text-gray-100">
                            {grouped(secret)}
                        </code>
                        <button
                            type="button"
                            onClick={copy}
                            className="min-h-11 shrink-0 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300"
                        >
                            {copied ? 'Copied' : 'Copy'}
                        </button>
                    </div>
                </div>
            </div>

            <form onSubmit={submit} className="flex gap-2">
                <input
                    ref={input}
                    aria-label="Code from your authenticator app"
                    value={code}
                    onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
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
