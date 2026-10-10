// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Recovery codes: how many are left (with a meter when the total is given)
// and a "New codes" button, which hands over to the page: it confirms, asks for
// the password when needed and shows the new codes in MfaRecoveryCodesDialog.
// Needs only React and ./icons (the icon set, swappable in one place); imports
// no other component and knows nothing about Inertia or routes.
//
//     <MfaRecoveryCodesPanel remaining={recoveryCodesRemaining} total={recoveryCodesTotal} onNewCodes={() => setCodesDialog(true)} />
import { MfaIconKey } from './icons';

export type MfaRecoveryCodesPanelProps = {
    remaining: number;
    /** How many a fresh set has (config recovery_codes.count); shows a meter when given. */
    total?: number | null;
    /** "New codes" was clicked: open MfaRecoveryCodesDialog, which asks before anything changes. */
    onNewCodes: () => void;
    /** New codes are being made: the button waits. */
    processing?: boolean;
};

export default function MfaRecoveryCodesPanel({ remaining, total = null, onNewCodes, processing = false }: MfaRecoveryCodesPanelProps) {
    const left = total ? `${remaining} of ${total} left` : `${remaining} left`;
    // Few enough that the user should act now.
    const low = remaining <= 2;

    return (
        <section>
            <article className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-3 rounded-2xl p-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:gap-x-5 sm:gap-y-4 sm:p-5 sm:px-6 border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-orange-700 sm:size-14 sm:rounded-xl dark:bg-orange-950 dark:text-orange-300">
                    <MfaIconKey size={28} className="size-6 sm:size-7" />
                </div>
                <div className="min-w-0 grow space-y-2">
                    <div className="space-y-1">
                        <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">Recovery codes</h3>
                        <p className={`text-sm ${low ? 'font-medium text-amber-700 dark:text-amber-300' : 'text-gray-600 dark:text-gray-400'}`}>
                            {low
                                ? `${left}. Make new ones before you run out.`
                                : (
                                      <>
                                          {/* Short on phones, so the card stays compact. */}
                                          <span className="sm:hidden">For when you can't use your methods.</span>
                                          <span className="hidden sm:inline">One-time codes for signing in without your methods. Keep them somewhere safe.</span>
                                      </>
                                  )}
                        </p>
                    </div>
                    {!low && (
                        <div className="flex items-center gap-3">
                            {total ? (
                                <div
                                    role="meter"
                                    aria-label="Recovery codes left"
                                    aria-valuemin={0}
                                    aria-valuemax={total}
                                    aria-valuenow={remaining}
                                    className="h-1.5 w-full max-w-60 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"
                                >
                                    <div className="h-1.5 rounded-full bg-orange-600" style={{ width: `${Math.min(100, (remaining / total) * 100)}%` }} />
                                </div>
                            ) : null}
                            <span className="shrink-0 text-sm font-medium text-gray-700 dark:text-gray-300">{left}</span>
                        </div>
                    )}
                </div>
                <button
                    type="button"
                    disabled={processing}
                    onClick={onNewCodes}
                    className="col-span-2 min-h-11 w-full shrink-0 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium sm:col-span-1 sm:w-auto text-gray-900 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                >
                    New codes
                </button>
            </article>
        </section>
    );
}
