// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The recovery-code step of the MFA challenge. Standalone: needs only React,
// imports no other MFA file, and knows nothing about Inertia or routes.
//
//     <MfaRecoveryCodeForm onSubmit={(code) => recover(code)} processing={processing} error={errors.code} />
import { useState } from 'react';

export type MfaRecoveryCodeFormProps = {
    /** Called with the code as typed (the server normalises it). */
    onSubmit: (code: string) => void;
    processing?: boolean;
    error?: string | null;
    /** Shows a "Use a verification code" link when given. */
    onUseVerificationCode?: () => void;
    /** Shows a "Sign out" link when given. */
    onSignOut?: () => void;
};

export default function MfaRecoveryCodeForm({ onSubmit, processing = false, error = null, onUseVerificationCode, onSignOut }: MfaRecoveryCodeFormProps) {
    const [code, setCode] = useState('');

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        onSubmit(code.trim());
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-500 dark:text-gray-400">Enter one of your recovery codes.</p>

            <form onSubmit={submit} className="space-y-3">
                <input
                    aria-label="Recovery code"
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder="xxxxx-xxxxx"
                    autoComplete="off"
                    autoFocus
                    className="w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                />
                {error && (
                    <p role="alert" className="text-sm text-red-600">
                        {error}
                    </p>
                )}
                <button
                    type="submit"
                    disabled={processing || code.trim() === ''}
                    className="w-full rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900"
                >
                    Verify
                </button>
            </form>

            {(onUseVerificationCode || onSignOut) && (
                <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    {onUseVerificationCode ? (
                        <button type="button" onClick={onUseVerificationCode} className="underline">
                            Use a verification code
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
