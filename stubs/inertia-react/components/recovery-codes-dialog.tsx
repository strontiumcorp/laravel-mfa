// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// New recovery codes, as one dialog:
//   1. "Generate new recovery codes?": the unused codes stop working.
//   2. Confirm your password (only when the server asks for it).
//   3. The new codes (Complete unlocks once they're copied or downloaded; no
//      way out before that, since they're shown once).
// The steps follow the props: `askPassword` shows the password, `codes` the
// codes. A bottom sheet on phones, a centred dialog from `sm` up, styled like
// MfaFactorSetupDialog. Needs only React and ./icons; imports no other
// component and knows nothing about Inertia or routes.
//
//     <MfaRecoveryCodesDialog open remaining={8} total={10} onGenerate={regenerate} processing={regenerating}
//         askPassword={passwordAsked} onConfirmPassword={confirmPassword}
//         codes={newCodes} recoveryCodesFile={file} onClose={close} onComplete={close} />
import { type KeyboardEvent, useEffect, useId, useRef, useState } from 'react';
import { MfaIconClose, MfaIconCopy, MfaIconDownload, MfaIconKey } from './icons';

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

    /** The new codes: shows the last step. */
    codes?: string[] | null;
    /** Cancel or close before the codes are made. */
    onClose: () => void;
    /** Complete, once the codes are copied or downloaded. */
    onComplete: () => void;
    /**
     * Who and what the downloaded codes are for (the settings prop
     * recoveryCodesFile): the file is named "{slug}-recovery-codes-{account}-{date}.txt",
     * with the browser's local date, and its text names the app and account.
     */
    recoveryCodesFile?: { app: string; slug: string; account: string } | null;
    /** The downloaded file's name, instead of the one built from recoveryCodesFile. */
    downloadName?: string;
};

type Step = 'confirm' | 'password' | 'codes';

const pad = (n: number) => String(n).padStart(2, '0');
// Characters Windows, macOS or Linux won't take in a file name, and control characters.
// eslint-disable-next-line no-control-regex
const unsafeInFileName = (text: string) => text.replace(/[\\/:*?"<>|\u0000-\u001f\u007f]/g, '-');

/** The recovery codes file's name and text, dated in the browser's time zone. */
function recoveryCodesDownload(codes: string[], file: MfaRecoveryCodesDialogProps['recoveryCodesFile'], now: Date) {
    const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    let zone = '';
    try {
        zone = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
    } catch {
        // No Intl time zone support: the time goes without its zone.
    }
    const name = [file?.slug, 'recovery-codes', file?.account, date].filter(Boolean).join('-');
    const text = [
        file?.app ? `${file.app} two-factor recovery codes` : 'Two-factor recovery codes',
        ...(file?.account ? [`Account: ${file.account}`] : []),
        `Downloaded: ${date} ${pad(now.getHours())}:${pad(now.getMinutes())}${zone ? ` (${zone})` : ''}`,
        '',
        'Each line below is one code. Each code works once. Getting new codes cancels these.',
        '',
        ...codes,
        '',
    ].join('\n');

    return { name: `${unsafeInFileName(name)}.txt`, text };
}

/**
 * Copy to the clipboard, falling back to a selected textarea where the
 * Clipboard API is missing or blocked (old browsers, webviews, iframes).
 */
async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try {
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            area.remove();
        }
    }
}

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

const PRIMARY = 'min-h-11 rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900';
const SECONDARY =
    'min-h-11 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-900 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100';
// Text fields set every part of their look (type, border, padding, colours, focus ring), so a host's
// form styles (@tailwindcss/forms, a global `input {}` rule) can't change them.
const INPUT =
    'min-h-11 w-full appearance-none rounded-lg border border-gray-300 bg-white px-3 py-0 text-sm text-gray-900 shadow-none placeholder:text-gray-400 focus:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:ring-offset-0 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-600 dark:focus:border-gray-100 dark:focus:ring-gray-100/20';

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
    recoveryCodesFile = null,
    downloadName,
}: MfaRecoveryCodesDialogProps) {
    const [password, setPassword] = useState('');
    const [saved, setSaved] = useState<'copied' | 'downloaded' | null>(null);
    const [copyFailed, setCopyFailed] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const titleId = useId();
    const passwordWait = useCountdown(passwordRetryAfter);

    // A fresh start every time it opens.
    useEffect(() => {
        if (!open) return;
        setPassword('');
        setSaved(null);
        setCopyFailed(false);
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

    // The codes are shown once: no way out but Complete.
    const closable = step !== 'codes';

    const title = {
        confirm: 'Generate new recovery codes?',
        password: 'Confirm your password',
        codes: 'Save your new recovery codes',
    }[step];

    const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
        if (e.key === 'Escape' && closable) {
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

    const copyCodes = async () => {
        const ok = await copyText((codes ?? []).join('\n'));
        setCopyFailed(!ok);
        if (ok) setSaved('copied');
    };

    const downloadCodes = () => {
        const { name, text } = recoveryCodesDownload(codes ?? [], recoveryCodesFile, new Date());
        const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = downloadName ?? name;
        // In the document and revoked a moment later: Firefox cancels a download otherwise.
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        setCopyFailed(false);
        setSaved('downloaded');
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
                    {closable && (
                        <button type="button" aria-label="Close" onClick={onClose} className="-mr-2 -mt-1 flex size-11 shrink-0 items-center justify-center rounded-lg text-gray-500 dark:text-gray-400">
                            <MfaIconClose size={20} />
                        </button>
                    )}
                </header>

                <form id={formId} onSubmit={submit} className="space-y-4 px-5 py-5 sm:px-6">
                    {step === 'confirm' && (
                        <>
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                {remaining > 0 ? `${unused} working as soon as the new ones are made.` : "You've used all your codes."} You'll get {fresh} to copy or download.
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
                                Your old codes no longer work. Each of these is a separate code; each works once, and they won't be shown again.
                            </p>
                            <ul aria-label="Recovery codes" className="grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-xl bg-gray-100 p-4 font-mono text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-100">
                                {codes!.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                            <div className="grid grid-cols-2 gap-2">
                                <button type="button" data-autofocus onClick={copyCodes} className={`${SECONDARY} inline-flex items-center justify-center gap-2`}>
                                    <MfaIconCopy size={16} />
                                    {saved === 'copied' ? 'Copied' : 'Copy'}
                                </button>
                                <button type="button" onClick={downloadCodes} className={`${SECONDARY} inline-flex items-center justify-center gap-2`}>
                                    <MfaIconDownload size={16} />
                                    {saved === 'downloaded' ? 'Downloaded' : 'Download'}
                                </button>
                            </div>
                            {copyFailed && (
                                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                    Couldn't copy here. Download the codes instead.
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
                            {!saved && <p className="text-center text-xs text-gray-500 sm:mr-auto sm:text-left dark:text-gray-400">Copy or download your codes to finish.</p>}
                            <button type="button" disabled={!saved} onClick={onComplete} className={PRIMARY}>
                                Complete
                            </button>
                        </>
                    )}
                </footer>
            </div>
        </div>
    );
}
