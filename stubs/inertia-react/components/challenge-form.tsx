// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The verification-code step of the MFA challenge: picks a factor and takes
// its code. Standalone: needs only React, imports no other MFA file, and
// knows nothing about Inertia or routes. Pass data and callbacks as props.
//
//     <MfaChallengeForm factors={factors} selectedFactorId={id} onSelectFactor={setId} onSubmit={(code) => verify(code)}>
//         <MfaSendCodeButton ... />
//     </MfaChallengeForm>
import { type ReactNode, useEffect, useRef, useState } from 'react';

export type MfaChallengeFactor = {
    id: number;
    type: 'totp' | 'email' | 'sms';
    type_label: string;
    label: string | null;
    /** Masked, e.g. "j***@example.com". */
    destination: string | null;
    /** Email/SMS: a usable code is already out (it survives a refresh). */
    code_sent?: boolean;
    /** Email/SMS: seconds until a resend is allowed, null when allowed now. */
    retry_after?: number | null;
};

export type MfaChallengeFormProps = {
    factors: MfaChallengeFactor[];
    selectedFactorId: number | null;
    onSelectFactor: (id: number) => void;
    /** Called with the digits the user entered. */
    onSubmit: (code: string) => void;
    processing?: boolean;
    error?: string | null;
    /** Rendered above the code input, e.g. a send-code button for email and SMS. */
    children?: ReactNode;
    /** Shows a "Use a recovery code" link when given. */
    onUseRecoveryCode?: () => void;
    /** Shows a "Sign out" link when given. */
    onSignOut?: () => void;
};

export default function MfaChallengeForm({
    factors,
    selectedFactorId,
    onSelectFactor,
    onSubmit,
    processing = false,
    error = null,
    children,
    onUseRecoveryCode,
    onSignOut,
}: MfaChallengeFormProps) {
    const [code, setCode] = useState('');
    const factor = factors.find((f) => f.id === selectedFactorId) ?? null;

    // A new factor means a new code.
    useEffect(() => setCode(''), [selectedFactorId]);

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
        onSubmit(code);
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {factor?.type === 'totp'
                    ? 'Enter the 6-digit code from your authenticator app.'
                    : factor?.code_sent
                      ? `We sent a code to ${factor.destination ?? 'you'}.`
                      : `We'll send a code to ${factor?.destination ?? 'you'}.`}
            </p>

            {factors.length > 1 && (
                <div role="group" aria-label="Verification method" className="flex flex-wrap gap-2">
                    {factors.map((f) => (
                        <button
                            key={f.id}
                            type="button"
                            aria-pressed={f.id === selectedFactorId}
                            onClick={() => onSelectFactor(f.id)}
                            className={`rounded-md border px-3 py-1.5 text-xs font-medium ${
                                f.id === selectedFactorId
                                    ? 'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900'
                                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'
                            }`}
                        >
                            {f.label ?? f.type_label}
                        </button>
                    ))}
                </div>
            )}

            {children}

            <form onSubmit={submit} className="space-y-3">
                <input
                    ref={input}
                    aria-label="Verification code"
                    value={code}
                    onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 10))}
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    placeholder="123456"
                    autoFocus
                    className="w-full rounded-md border border-gray-300 px-3 py-2 text-center font-mono text-lg tracking-[0.4em] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                />
                {error && (
                    <p role="alert" className="text-sm text-red-600">
                        {error}
                    </p>
                )}
                <button
                    type="submit"
                    disabled={processing || code.length < 4}
                    className="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                >
                    Verify
                </button>
            </form>

            {(onUseRecoveryCode || onSignOut) && (
                <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    {onUseRecoveryCode ? (
                        <button type="button" onClick={onUseRecoveryCode} className="underline">
                            Use a recovery code
                        </button>
                    ) : (
                        <span />
                    )}
                    {onSignOut && (
                        <button type="button" onClick={onSignOut} className="underline">
                            Sign out
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
