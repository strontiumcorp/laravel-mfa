// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// New recovery codes, as one dialog:
//   1. "Generate new recovery codes?": the unused ones stop working once
//      the new ones are saved.
//   2. Confirm your password (only when the server asks for it).
//   3. The new codes: Download (the page gets the file from the server,
//      which records it), then Finish, which replaces the old set. No Copy.
//      Closing here drops the new set and keeps the old one.
// The steps follow the props: `askPassword` shows the password, `codes` the
// codes. A bottom sheet on phones, a centred dialog from `sm` up, styled like
// MfaFactorSetupDialog. Needs only React and ./icons; imports no other
// component and knows nothing about Inertia or routes.
//
//     <MfaRecoveryCodesDialog open remaining={8} total={10} onGenerate={regenerate} processing={regenerating}
//         askPassword={passwordAsked} onConfirmPassword={confirmPassword}
//         codes={newCodes} onDownload={download} downloaded={downloaded} onClose={discard} onComplete={finish} />
import { type KeyboardEvent, useEffect, useId, useRef, useState } from 'react';
import { MfaIconCheck, MfaIconClose, MfaIconDownload, MfaIconKey } from './icons';

export type MfaRecoveryCodesDialogProps = {
    open: boolean;
    /** Unused codes that stop working (the settings prop recoveryCodesRemaining). */
    remaining: number;
    /** How many the new set has (recoveryCodesTotal). */
    total?: number | null;
    /** "Generate new codes" was confirmed. */
    onGenerate: () => void;
    processing?: boolean;
    /** Why generating failed. */
    error?: string | null;

    /** Ask for the password (the server answered "confirm your password first"). */
    askPassword?: boolean;
    onConfirmPassword?: (password: string) => void;
    passwordProcessing?: boolean;
    passwordError?: string | null;
    /** Seconds until another password attempt is allowed. */
    passwordRetryAfter?: number | null;

    /** The new codes, waiting to be saved: shows the last step. */
    codes?: string[] | null;
    /** Download: the page fetches the file from the server (which notes it) and saves it. */
    onDownload?: () => void;
    downloading?: boolean;
    /** The file was downloaded: unlocks Finish. */
    downloaded?: boolean;
    /** Why the download or Finish failed. */
    downloadError?: string | null;
    /** Cancel or close; on the codes step it drops the new set (the old one stays). */
    onClose: () => void;
    /** Finish, once the codes are downloaded: they replace the old set. */
    onComplete: () => void;
    /** Finish is saving. */
    completing?: boolean;
};

type Step = 'confirm' | 'password' | 'codes';

/** Seconds left until something is allowed again; ticks down to 0. */
function useCountdown(seconds: number | null): number {
    const [left, setLeft] = useState(seconds ?? 0);

    // Each new response restarts the countdown.
    useEffect(() => setLeft(seconds ?? 0), [seconds]);

    useEffect(() => {
        if (left <= 0) return;
        const timer = setTimeout(() => setLeft((s) => s - 1), 1000);
        return () => clearTimeout(timer);
    }, [left]);

    return left;
}

const formatWait = (s: number) => (s >= 3600 ? `${Math.ceil(s / 3600)} h` : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`);

const PRIMARY = 'min-h-11 rounded-lg bg-[color:var(--mfa-primary,#111827)] px-5 text-sm font-semibold text-[color:var(--mfa-primary-foreground,#ffffff)] disabled:opacity-50 dark:bg-[color:var(--mfa-primary,#f3f4f6)] dark:text-[color:var(--mfa-primary-foreground,#111827)]';
const SECONDARY =
    'min-h-11 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-900 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100';
// Text fields set every part of their look (type, border, padding, colours, focus ring), so a host's
// form styles (@tailwindcss/forms, a global `input {}` rule) can't change them.
const INPUT =
    'min-h-11 w-full appearance-none rounded-lg border border-gray-300 bg-white px-3 py-0 text-sm text-gray-900 shadow-none placeholder:text-gray-400 focus:border-[color:var(--mfa-primary,#111827)] focus:outline-none focus:ring-2 focus:ring-[color:color-mix(in_oklab,var(--mfa-primary,#111827)_10%,transparent)] focus:ring-offset-0 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-600 dark:focus:border-[color:var(--mfa-primary,#f3f4f6)] dark:focus:ring-[color:color-mix(in_oklab,var(--mfa-primary,#f3f4f6)_20%,transparent)]';

export default function MfaRecoveryCodesDialog({
    open,
    remaining,
    total = null,
    onGenerate,
    processing = false,
    error = null,
    askPassword = false,
    onConfirmPassword,
    passwordProcessing = false,
    passwordError = null,
    passwordRetryAfter = null,
    codes = null,
    onClose,
    onComplete,
    completing = false,
    onDownload,
    downloading = false,
    downloaded = false,
    downloadError = null,
}: MfaRecoveryCodesDialogProps) {
    const [password, setPassword] = useState('');
    const panel = useRef<HTMLDivElement>(null);
    const titleId = useId();
    const passwordWait = useCountdown(passwordRetryAfter);

    // A fresh start every time it opens.
    useEffect(() => {
        if (!open) return;
        setPassword('');
    }, [open]);

    // A wrong password clears.
    const wasPasswordProcessing = useRef(passwordProcessing);
    useEffect(() => {
        if (wasPasswordProcessing.current && !passwordProcessing && passwordError) setPassword('');
        wasPasswordProcessing.current = passwordProcessing;
    }, [passwordProcessing, passwordError]);

    const hasCodes = !!codes && codes.length > 0;
    const step: Step = hasCodes ? 'codes' : askPassword ? 'password' : 'confirm';

    // While open: page scroll locked, focus inside (the step's first field or
    // button), and back on the opener afterwards.
    useEffect(() => {
        if (!open) return;
        const opener = document.activeElement as HTMLElement | null;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = overflow;
            opener?.focus?.();
        };
    }, [open]);
    useEffect(() => {
        if (!open) return;
        const target = panel.current?.querySelector<HTMLElement>('[data-autofocus]') ?? panel.current;
        target?.focus();
    }, [open, step]);

    if (!open) return null;

    const title = {
        confirm: 'Generate new recovery codes?',
        password: 'Confirm your password',
        codes: 'Save your new recovery codes',
    }[step];

    const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
        // Closing drops a new set that isn't saved yet: the old codes keep working.
        if (e.key === 'Escape') {
            e.stopPropagation();
            onClose();
        }
        if (e.key !== 'Tab' || !panel.current) return;
        // Keep Tab inside the dialog.
        const focusable = Array.from(panel.current.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled])'));
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last?.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first?.focus();
        }
    };

    const formId = `${titleId}-form`;
    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        if (step === 'confirm' && !processing) onGenerate();
        if (step === 'password' && password !== '' && !passwordProcessing && passwordWait === 0) onConfirmPassword?.(password);
    };

    const fresh = total ? `${total} new codes` : 'a new set of codes';
    const unused = remaining === 1 ? 'Your 1 unused code stops' : `Your ${remaining} unused codes stop`;

    return (
        // margin 0 inline: a parent's space-y-* would otherwise push the fixed overlay down.
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-gray-950/50 sm:items-center sm:p-4" style={{ margin: 0 }} onKeyDown={onKeyDown}>
            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                className="flex max-h-[100dvh] w-full flex-col overflow-y-auto rounded-t-2xl bg-white shadow-xl outline-none sm:max-h-[90vh] sm:max-w-md sm:rounded-2xl dark:bg-gray-900"
            >
                <header className="flex items-start gap-4 px-5 pt-5 sm:px-6 sm:pt-6">
                    <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                        <MfaIconKey size={24} />
                    </div>
                    <div className="min-w-0 grow space-y-1">
                        <h2 id={titleId} className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {title}
                        </h2>
                        <p className="text-sm text-gray-500 dark:text-gray-400">Recovery codes</p>
                    </div>
                    <button type="button" aria-label="Close" onClick={onClose} className="-mr-2 -mt-1 flex size-11 shrink-0 items-center justify-center rounded-lg text-gray-500 dark:text-gray-400">
                        <MfaIconClose size={20} />
                    </button>
                </header>

                <form id={formId} onSubmit={submit} className="space-y-4 px-5 py-5 sm:px-6">
                    {step === 'confirm' && (
                        <>
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                {remaining > 0 ? `${unused} working once you've downloaded the new ones and pressed Finish.` : "You've used all your codes."} You'll get {fresh} to download.
                            </p>
                            {error && (
                                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                    {error}
                                </p>
                            )}
                        </>
                    )}

                    {step === 'password' && (
                        <>
                            <label htmlFor={`${titleId}-password`} className="block text-sm text-gray-600 dark:text-gray-400">
                                For your security, enter your password to make new recovery codes.
                            </label>
                            <input
                                id={`${titleId}-password`}
                                data-autofocus
                                type="password"
                                aria-label="Password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                autoComplete="current-password"
                                className={INPUT}
                            />
                            {passwordError && (
                                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                    {passwordError}
                                </p>
                            )}
                        </>
                    )}

                    {step === 'codes' && (
                        <>
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                Download these and press Finish to start using them; until then your old codes keep working. Each code works once. Keep the file somewhere safe, not only on this device.
                            </p>
                            <ul aria-label="Recovery codes" className="grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-xl bg-gray-100 p-4 font-mono text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-100">
                                {codes!.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                            <button
                                type="button"
                                data-autofocus
                                disabled={downloading}
                                onClick={onDownload}
                                className={`${downloaded ? SECONDARY : PRIMARY} inline-flex w-full items-center justify-center gap-2`}
                            >
                                {downloaded ? <MfaIconCheck size={16} /> : <MfaIconDownload size={16} />}
                                {downloading ? 'Downloading…' : downloaded ? 'Downloaded. Download again' : 'Download recovery codes'}
                            </button>
                            {downloadError && (
                                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                    {downloadError}
                                </p>
                            )}
                        </>
                    )}
                </form>

                <footer className="mt-auto flex flex-col-reverse gap-2 border-t border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-6 dark:border-gray-800">
                    {step === 'confirm' && (
                        <>
                            {/* The focus starts on Cancel: the old codes are lost on confirm. */}
                            <button type="button" data-autofocus onClick={onClose} className={SECONDARY}>
                                Cancel
                            </button>
                            <button type="submit" form={formId} disabled={processing} aria-busy={processing || undefined} className={PRIMARY}>
                                {processing ? 'Generating…' : 'Generate new codes'}
                            </button>
                        </>
                    )}
                    {step === 'password' && (
                        <>
                            <button type="button" onClick={onClose} className={SECONDARY}>
                                Cancel
                            </button>
                            <button type="submit" form={formId} disabled={password === '' || passwordProcessing || passwordWait > 0} className={PRIMARY}>
                                {passwordWait > 0 ? `Try again in ${formatWait(passwordWait)}` : 'Continue'}
                            </button>
                        </>
                    )}
                    {step === 'codes' && (
                        <>
                            {!downloaded && <p className="text-center text-xs text-gray-500 sm:mr-auto sm:text-left dark:text-gray-400">Download your codes to finish.</p>}
                            <button type="button" disabled={!downloaded || completing} onClick={onComplete} className={PRIMARY}>
                                {completing ? 'Finishing…' : 'Finish'}
                            </button>
                        </>
                    )}
                </footer>
            </div>
        </div>
    );
}
