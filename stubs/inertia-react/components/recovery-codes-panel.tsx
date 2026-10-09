// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Recovery codes: how many are left, a Regenerate button, and newly generated
// codes (shown once) with a copy button. Standalone: needs only React,
// imports no other MFA file, and knows nothing about Inertia or routes.
//
//     <MfaRecoveryCodesPanel remaining={recoveryCodesRemaining} codes={recoveryCodes} onRegenerate={regenerate} />
import { useEffect, useState } from 'react';

export type MfaRecoveryCodesPanelProps = {
    remaining: number;
    /** Codes generated just now; null once they've been shown. */
    codes?: string[] | null;
    /** Called after the user confirms. */
    onRegenerate: () => void;
    processing?: boolean;
    /** Asks before regenerating; defaults to window.confirm(). */
    confirmRegenerate?: () => boolean;
};

export default function MfaRecoveryCodesPanel({
    remaining,
    codes = null,
    onRegenerate,
    processing = false,
    confirmRegenerate = () => window.confirm('Generate new codes? Your old codes will stop working.'),
}: MfaRecoveryCodesPanelProps) {
    const [copied, setCopied] = useState(false);

    // New codes, new copy.
    useEffect(() => setCopied(false), [codes]);

    const copy = () => {
        if (!codes) return;
        navigator.clipboard?.writeText(codes.join('\n')).then(
            () => setCopied(true),
            () => setCopied(false),
        );
    };

    return (
        <section className="space-y-3">
            {codes && codes.length > 0 && (
                <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
                    <div>
                        <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Save your recovery codes</h2>
                        <p className="text-xs text-gray-600 dark:text-gray-400">Each code works once. Store them somewhere safe — they won't be shown again.</p>
                    </div>
                    <ul aria-label="Recovery codes" className="grid grid-cols-2 gap-1 font-mono text-sm text-gray-900 dark:text-gray-100">
                        {codes.map((c) => (
                            <li key={c}>{c}</li>
                        ))}
                    </ul>
                    <button type="button" onClick={copy} className="text-xs font-medium underline">
                        {copied ? 'Copied' : 'Copy all'}
                    </button>
                </div>
            )}

            <div className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                <div>
                    <p className="text-sm font-medium text-gray-900 dark:text-gray-100">Recovery codes</p>
                    <p className="text-xs text-gray-500">{remaining} remaining. Use one if you lose access to your methods.</p>
                </div>
                <button
                    type="button"
                    disabled={processing}
                    onClick={() => confirmRegenerate() && onRegenerate()}
                    className="text-xs font-medium text-gray-700 underline disabled:opacity-50 dark:text-gray-300"
                >
                    Regenerate
                </button>
            </div>
        </section>
    );
}
