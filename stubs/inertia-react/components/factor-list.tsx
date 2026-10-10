// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The user's confirmed MFA methods, each with a Remove button (it asks in the
// row before removing). Standalone:
// needs only React, imports no other MFA file, and knows nothing about
// Inertia or routes.
//
//     <MfaFactorList factors={factors} required={mustEnroll} requiredLabels={['Authenticator app']} onRemove={(factor) => destroy(factor.id)} />
import { useState } from 'react';

export type MfaListedFactor = {
    id: number;
    type: 'totp' | 'email' | 'sms';
    type_label: string;
    label: string | null;
    /** Masked, e.g. "j***@example.com". */
    destination: string | null;
    last_used_at: string | null;
};

export type MfaFactorListProps<F extends MfaListedFactor = MfaListedFactor> = {
    factors: F[];
    /** Called once the user confirms the removal in the method's row. */
    onRemove: (factor: F) => void;
    /** The factor being removed; its button is disabled. */
    removingId?: number | null;
    /** The account must enroll (an enforcement rule applies): shows a notice above the list. */
    required?: boolean;
    /** The methods that satisfy the requirement, e.g. ["Authenticator app"]; empty means any. */
    requiredLabels?: string[];
};

export default function MfaFactorList<F extends MfaListedFactor>({
    factors,
    onRemove,
    removingId = null,
    required = false,
    requiredLabels = [],
}: MfaFactorListProps<F>) {
    const [confirmingId, setConfirmingId] = useState<number | null>(null);

    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">Your methods</h2>

            {required && (
                <p role="note" className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    {requiredLabels.length === 0
                        ? 'Your account requires two-factor authentication. Set up a method to continue.'
                        : `Your account requires two-factor authentication with: ${requiredLabels.join(' or ')}. Set ${requiredLabels.length === 1 ? 'it' : 'one'} up to continue.`}
                </p>
            )}

            {factors.length === 0 && !required && <p className="text-sm text-gray-500 dark:text-gray-400">No methods set up yet.</p>}

            {factors.length > 0 && (
                <ul className="space-y-3">
                    {factors.map((f) => (
                        <li key={f.id} className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                            <div>
                                <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{f.label ?? f.type_label}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">
                                    {f.destination ?? f.type_label}
                                    {f.last_used_at && ` · last used ${new Date(f.last_used_at).toLocaleDateString()}`}
                                </p>
                            </div>
                            {confirmingId === f.id ? (
                                <div role="group" aria-label={`Remove ${f.label ?? f.type_label}?`} className="flex items-center gap-3 text-xs font-medium">
                                    <span className="text-gray-700 dark:text-gray-300">Remove?</span>
                                    <button type="button" autoFocus onClick={() => setConfirmingId(null)} className="text-gray-600 dark:text-gray-400">
                                        Cancel
                                    </button>
                                    <button type="button" disabled={removingId === f.id} onClick={() => onRemove(f)} className="text-red-600 disabled:opacity-50 dark:text-red-400">
                                        Remove
                                    </button>
                                </div>
                            ) : (
                                <button
                                    type="button"
                                    aria-label={`Remove ${f.label ?? f.type_label}`}
                                    disabled={removingId === f.id}
                                    onClick={() => setConfirmingId(f.id)}
                                    className="text-xs font-medium text-red-600 dark:text-red-400 disabled:opacity-50"
                                >
                                    Remove
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
