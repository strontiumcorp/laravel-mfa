// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Adding a sign-in method, as one dialog for every type:
//   1. Confirm your password (only when the change needs it).
//   2. Authenticator app: scan the QR code or enter the key ("Open in
//      authenticator app" on phones). Email/SMS: the address or number.
//   3. Enter the code.
//   4. Save the recovery codes (Complete unlocks once they're copied or
//      downloaded), or a short "added" step when there are no new ones.
// The steps follow the props: a password requested mid-way (it expired)
// brings that step back, and a code that's been sent moves on to the code.
// A bottom sheet on phones, a centred dialog from `sm` up. Needs only React and
// ./icons; imports no other component and knows nothing about Inertia or routes.
//
//     <MfaFactorSetupDialog open type="sms" label="SMS"
//         askPassword={needsPassword} onConfirmPassword={confirmPassword}
//         onSubmitDestination={(to) => store('sms', to)} sentTo={pending?.destination}
//         onConfirm={(code) => confirm(pending.id, code)} onResend={resend}
//         confirmed={confirmed} recoveryCodes={codes} onClose={close} onComplete={done} />
import { type KeyboardEvent, useEffect, useId, useRef, useState } from 'react';
import { MfaFactorIcon, MfaIconCheck, MfaIconClose, MfaIconCopy, MfaIconDownload, type MfaFactorType } from './icons';

export type MfaFactorSetupDialogProps = {
    open: boolean;
    type: MfaFactorType;
    /** The method's display label, e.g. "Authenticator app". */
    label: string;

    /** Ask for the password before anything else (or again, if it expired mid-way). */
    askPassword?: boolean;
    onConfirmPassword?: (password: string) => void;
    passwordProcessing?: boolean;
    passwordError?: string | null;
    /** Seconds until another password attempt is allowed. */
    passwordRetryAfter?: number | null;

    /** Email/SMS: called with the address or number as typed (empty email = the account email). */
    onSubmitDestination?: (destination: string) => void;
    destinationProcessing?: boolean;
    destinationError?: string | null;

    /** Authenticator app: the base32 key, once the setup has started (null shows "getting your key"). */
    secret?: string | null;
    /**
     * The QR code as SVG markup. It is rendered as HTML, so pass only the
     * server's own output (`qr_svg`), never user input.
     */
    qrSvg?: string | null;
    /** The otpauth:// link (`otpauth_url`): "Open in authenticator app" on phones. */
    otpauthUrl?: string | null;

    /** Email/SMS: where the code went (masked), once sent; moves on to the code. */
    sentTo?: string | null;
    onResend?: () => void;
    resending?: boolean;
    /** Seconds until another code can be sent; a new value restarts the countdown. */
    retryAfter?: number | null;

    /** Called with the digits the user entered. */
    onConfirm: (code: string) => void;
    processing?: boolean;
    error?: string | null;
    /** The code was accepted: moves on to the last step. */
    confirmed?: boolean;
    /** Recovery codes from that confirmation, shown once. */
    recoveryCodes?: string[] | null;

    /** Cancel or close before the code is confirmed. */
    onClose: () => void;
    /** Complete / Done after the code is confirmed. */
    onComplete: () => void;
    /**
     * Who and what the downloaded recovery codes are for (the settings prop
     * recoveryCodesFile): the file is named "{slug}-recovery-codes-{account}-{date}.txt",
     * with the browser's local date, and its text names the app and account.
     */
    recoveryCodesFile?: MfaRecoveryCodesFile | null;
    /** The downloaded file's name, instead of the one built from recoveryCodesFile. */
    downloadName?: string;
};

export type MfaRecoveryCodesFile = {
    /** The app as authenticator apps show it, e.g. "Acme (staging)". */
    app: string;
    /** The same for a file name, e.g. "acme-staging". */
    slug: string;
    /** The account, e.g. the user's email. */
    account: string;
};

const pad = (n: number) => String(n).padStart(2, '0');
// Characters Windows, macOS or Linux won't take in a file name, and control characters.
// eslint-disable-next-line no-control-regex
const unsafeInFileName = (text: string) => text.replace(/[\\/:*?"<>|\u0000-\u001f\u007f]/g, '-');

/** The recovery codes file's name and text, dated in the browser's time zone. */
function recoveryCodesDownload(codes: string[], file: MfaRecoveryCodesFile | null | undefined, now: Date) {
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
        'Each code works once. Getting new codes cancels these.',
        '',
        ...codes,
        '',
    ].join('\n');

    return { name: `${unsafeInFileName(name)}.txt`, text };
}

type Step = 'password' | 'scan' | 'destination' | 'code' | 'saved';

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

const grouped = (key: string) => key.replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ');

/** After a failure, select what was typed so the user sees it and typing replaces it. */
function useSelectOnFailure(processing: boolean, error: string | null) {
    const input = useRef<HTMLInputElement>(null);
    const wasProcessing = useRef(processing);
    useEffect(() => {
        if (wasProcessing.current && !processing && error) {
            input.current?.focus();
            input.current?.select();
        }
        wasProcessing.current = processing;
    }, [processing, error]);

    return input;
}

const PRIMARY = 'min-h-11 rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900';
const SECONDARY =
    'min-h-11 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-900 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100';
// Text fields set every part of their look (type, border, padding, colours, focus ring), so a host's
// form styles (@tailwindcss/forms, a global `input {}` rule) can't change them.
const FIELD =
    'appearance-none rounded-lg border border-gray-300 bg-white text-gray-900 shadow-none placeholder:text-gray-400 focus:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:ring-offset-0 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-600 dark:focus:border-gray-100 dark:focus:ring-gray-100/20';
const INPUT = `${FIELD} min-h-11 w-full px-3 py-0 text-sm`;

const TILE: Record<MfaFactorType, string> = {
    totp: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
    email: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    sms: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
};

export default function MfaFactorSetupDialog({
    open,
    type,
    label,
    askPassword = false,
    onConfirmPassword,
    passwordProcessing = false,
    passwordError = null,
    passwordRetryAfter = null,
    onSubmitDestination,
    destinationProcessing = false,
    destinationError = null,
    secret = null,
    qrSvg = null,
    otpauthUrl = null,
    sentTo = null,
    onResend,
    resending = false,
    retryAfter = null,
    onConfirm,
    processing = false,
    error = null,
    confirmed = false,
    recoveryCodes = null,
    onClose,
    onComplete,
    recoveryCodesFile = null,
    downloadName,
}: MfaFactorSetupDialogProps) {
    const totp = type === 'totp';
    const [password, setPassword] = useState('');
    const [destination, setDestination] = useState('');
    const [code, setCode] = useState('');
    // Authenticator: the user pressed Next. Email/SMS: the user went back to change the destination.
    const [scanned, setScanned] = useState(false);
    const [changing, setChanging] = useState(false);
    // Once asked, the password stays in the step list, so the numbering doesn't jump.
    const [passwordStep, setPasswordStep] = useState(askPassword);
    const [keyCopied, setKeyCopied] = useState(false);
    const [saved, setSaved] = useState<'copied' | 'downloaded' | null>(null);
    const [copyFailed, setCopyFailed] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const titleId = useId();
    const passwordWait = useCountdown(passwordRetryAfter);
    const resendWait = useCountdown(retryAfter);

    // A fresh start every time it opens.
    useEffect(() => {
        if (!open) return;
        setPassword('');
        setDestination('');
        setCode('');
        setScanned(false);
        setChanging(false);
        setPasswordStep(askPassword);
        setKeyCopied(false);
        setSaved(null);
        setCopyFailed(false);
    }, [open]); // Only on open: askPassword is read as it is then.
    useEffect(() => {
        if (askPassword) setPasswordStep(true);
    }, [askPassword]);
    // A new code went out: on to the code, empty. (sentTo alone misses a
    // re-send to the same number, so a send that finished without an error counts too.)
    useEffect(() => {
        setChanging(false);
        setCode('');
    }, [sentTo]);
    const wasSending = useRef(destinationProcessing);
    useEffect(() => {
        if (wasSending.current && !destinationProcessing && !destinationError) {
            setChanging(false);
            setCode('');
        }
        wasSending.current = destinationProcessing;
    }, [destinationProcessing, destinationError]);

    // A wrong password clears; a wrong destination or code stays, selected.
    const wasPasswordProcessing = useRef(passwordProcessing);
    useEffect(() => {
        if (wasPasswordProcessing.current && !passwordProcessing && passwordError) setPassword('');
        wasPasswordProcessing.current = passwordProcessing;
    }, [passwordProcessing, passwordError]);
    const destinationInput = useSelectOnFailure(destinationProcessing, destinationError);
    const codeInput = useSelectOnFailure(processing, error);

    const step: Step = confirmed
        ? 'saved'
        : askPassword
          ? 'password'
          : totp
            ? scanned && secret
                ? 'code'
                : 'scan'
            : sentTo && !changing
              ? 'code'
              : 'destination';

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
    }, [open, step, secret]);

    if (!open) return null;

    const hasCodes = !!recoveryCodes && recoveryCodes.length > 0;
    // Recovery codes are shown once: no way out but Complete.
    const closable = step !== 'saved';
    const steps: Step[] = [...(passwordStep ? (['password'] as const) : []), totp ? 'scan' : 'destination', 'code', 'saved'];
    const stepNumber = steps.indexOf(step) + 1;
    const where = type === 'sms' ? 'number' : 'address';

    const title = {
        password: 'Confirm your password',
        scan: 'Set up an authenticator app',
        destination: type === 'sms' ? 'Add a phone number' : 'Add an email address',
        code: 'Enter the code',
        saved: hasCodes ? 'Save your recovery codes' : `${label} added`,
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

    const copyKey = async () => setKeyCopied(await copyText((secret ?? '').replace(/\s+/g, '')));

    const copyCodes = async () => {
        const ok = await copyText((recoveryCodes ?? []).join('\n'));
        setCopyFailed(!ok);
        if (ok) setSaved('copied');
    };

    const downloadCodes = () => {
        const { name, text } = recoveryCodesDownload(recoveryCodes ?? [], recoveryCodesFile, new Date());
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
        if (step === 'password') onConfirmPassword?.(password);
        if (step === 'destination') onSubmitDestination?.(destination.trim());
        if (step === 'code') onConfirm(code);
    };
    // Authenticator codes are always 6 digits; email/SMS lengths are configurable.
    const codeLength = totp ? 6 : 10;
    const ready = {
        password: password !== '' && !passwordProcessing && passwordWait === 0,
        destination: !destinationProcessing && (type === 'email' || destination.trim() !== ''),
        code: !processing && code.length >= (totp ? 6 : 4),
    };

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
                    <div className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${TILE[type]}`}>
                        <MfaFactorIcon type={type} size={24} />
                    </div>
                    <div className="min-w-0 grow space-y-1">
                        <h2 id={titleId} className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {title}
                        </h2>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {label} · Step {stepNumber} of {steps.length}
                        </p>
                    </div>
                    {closable && (
                        <button type="button" aria-label="Close" onClick={onClose} className="-mr-2 -mt-1 flex size-11 shrink-0 items-center justify-center rounded-lg text-gray-500 dark:text-gray-400">
                            <MfaIconClose size={20} />
                        </button>
                    )}
                </header>

                <div aria-hidden className="flex gap-1.5 px-5 pt-4 sm:px-6">
                    {steps.map((s, i) => (
                        <span key={s} className={`h-1 grow rounded-full ${i < stepNumber ? 'bg-gray-900 dark:bg-gray-100' : 'bg-gray-200 dark:bg-gray-700'}`} />
                    ))}
                </div>

                <form id={formId} onSubmit={submit} className="space-y-4 px-5 py-5 sm:px-6">
                    {step === 'password' && (
                        <>
                            <label htmlFor={`${titleId}-password`} className="block text-sm text-gray-600 dark:text-gray-400">
                                For your security, enter your password to add a sign-in method.
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

                    {step === 'scan' && (
                        <>
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                Use Google Authenticator, 1Password, Authy or a similar app.
                                <span className={otpauthUrl ? 'hidden sm:inline' : undefined}> Scan this QR code with it.</span>
                                {otpauthUrl && <span className="sm:hidden"> On this phone, open it directly:</span>}
                            </p>
                            {!secret && (
                                <p role="status" className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Getting your setup key…
                                </p>
                            )}
                            {secret && otpauthUrl && (
                                <a href={otpauthUrl} className={`${PRIMARY} flex items-center justify-center sm:hidden`}>
                                    Open in authenticator app
                                </a>
                            )}
                            {secret && qrSvg && (
                                <div
                                    role="img"
                                    aria-label="QR code for your authenticator app"
                                    className="mx-auto w-48 rounded-xl bg-white p-3 ring-1 ring-gray-200 dark:ring-gray-700"
                                    dangerouslySetInnerHTML={{ __html: qrSvg }}
                                />
                            )}
                            {secret && (
                                <div className="space-y-2">
                                    <p className="text-sm font-medium text-gray-900 dark:text-gray-100">Can't scan? Enter this key</p>
                                    <div className="flex items-center gap-2">
                                        <code aria-label="Setup key" className="min-w-0 grow break-words rounded-lg bg-gray-100 px-3 py-2.5 font-mono text-sm tracking-wide text-gray-900 dark:bg-gray-800 dark:text-gray-100">
                                            {grouped(secret)}
                                        </code>
                                        <button type="button" onClick={copyKey} className={`${SECONDARY} inline-flex shrink-0 items-center gap-2`}>
                                            <MfaIconCopy size={16} />
                                            {keyCopied ? 'Copied' : 'Copy'}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}

                    {step === 'destination' && (
                        <>
                            <label htmlFor={`${titleId}-destination`} className="block text-sm text-gray-600 dark:text-gray-400">
                                {type === 'sms' ? "We'll text a code to this number to check it's yours. Include the country code." : "We'll email a code to this address to check it's yours. Leave it empty to use your account email."}
                            </label>
                            <input
                                id={`${titleId}-destination`}
                                ref={destinationInput}
                                data-autofocus
                                aria-label={type === 'sms' ? 'Phone number, with country code' : 'Email address'}
                                value={destination}
                                onChange={(e) => setDestination(e.target.value)}
                                type={type === 'sms' ? 'tel' : 'email'}
                                autoComplete={type === 'sms' ? 'tel' : 'email'}
                                placeholder={type === 'sms' ? '+1 555 555 0100' : 'you@example.com'}
                                className={INPUT}
                            />
                            {destinationError && (
                                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                    {destinationError}
                                </p>
                            )}
                        </>
                    )}

                    {step === 'code' && (
                        <>
                            <label htmlFor={`${titleId}-code`} className="block text-sm text-gray-600 dark:text-gray-400">
                                {totp ? (
                                    'Enter the 6-digit code your authenticator app shows for this account.'
                                ) : (
                                    <>
                                        We sent a code to <span className="font-medium text-gray-900 dark:text-gray-100">{sentTo}</span>.
                                    </>
                                )}
                            </label>
                            <input
                                id={`${titleId}-code`}
                                ref={codeInput}
                                data-autofocus
                                aria-label={totp ? 'Code from your authenticator app' : 'Verification code'}
                                value={code}
                                onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, codeLength))}
                                type="text"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                placeholder="123456"
                                className={`${FIELD} min-h-14 w-full px-4 py-0 text-center font-mono text-2xl tracking-[0.4em]`}
                            />
                            {error && (
                                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                    {error}
                                </p>
                            )}
                            {!totp && (
                                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <button
                                        type="button"
                                        disabled={resending || resendWait > 0}
                                        onClick={onResend}
                                        className="min-h-11 font-medium text-gray-700 underline disabled:no-underline disabled:opacity-60 dark:text-gray-300"
                                    >
                                        {resendWait > 0 ? `Resend in ${formatWait(resendWait)}` : 'Resend code'}
                                    </button>
                                    <button type="button" onClick={() => setChanging(true)} className="min-h-11 font-medium text-gray-700 underline dark:text-gray-300">
                                        Use another {where}
                                    </button>
                                </div>
                            )}
                        </>
                    )}

                    {step === 'saved' && hasCodes && (
                        <>
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                If you lose access to your sign-in methods, use one of these. Each works once, and they won't be shown again.
                            </p>
                            <ul aria-label="Recovery codes" className="grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-xl bg-gray-100 p-4 font-mono text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-100">
                                {recoveryCodes!.map((c) => (
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

                    {step === 'saved' && !hasCodes && (
                        <div className="flex items-center gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300">
                                <MfaIconCheck size={16} />
                            </span>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Signing in can now ask for a code from this method.</p>
                        </div>
                    )}
                </form>

                <footer className="mt-auto flex flex-col-reverse gap-2 border-t border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-6 dark:border-gray-800">
                    {step === 'password' && (
                        <>
                            <button type="button" onClick={onClose} className={SECONDARY}>
                                Cancel
                            </button>
                            <button type="submit" form={formId} disabled={!ready.password} className={PRIMARY}>
                                {passwordWait > 0 ? `Try again in ${formatWait(passwordWait)}` : 'Continue'}
                            </button>
                        </>
                    )}
                    {step === 'scan' && (
                        <>
                            <button type="button" onClick={onClose} className={SECONDARY}>
                                Cancel
                            </button>
                            <button type="button" data-autofocus={otpauthUrl || !secret ? undefined : true} disabled={!secret} onClick={() => setScanned(true)} className={PRIMARY}>
                                Next
                            </button>
                        </>
                    )}
                    {step === 'destination' && (
                        <>
                            <button type="button" onClick={sentTo ? () => setChanging(false) : onClose} className={SECONDARY}>
                                {sentTo ? 'Back' : 'Cancel'}
                            </button>
                            <button type="submit" form={formId} disabled={!ready.destination} className={PRIMARY}>
                                Send code
                            </button>
                        </>
                    )}
                    {step === 'code' && (
                        <>
                            <button type="button" onClick={() => (totp ? setScanned(false) : setChanging(true))} className={SECONDARY}>
                                Back
                            </button>
                            <button type="submit" form={formId} disabled={!ready.code} className={PRIMARY}>
                                Confirm
                            </button>
                        </>
                    )}
                    {step === 'saved' && (
                        <>
                            {hasCodes && !saved && <p className="text-center text-xs text-gray-500 sm:mr-auto sm:text-left dark:text-gray-400">Copy or download your codes to finish.</p>}
                            <button type="button" data-autofocus={hasCodes ? undefined : true} disabled={hasCodes && !saved} onClick={onComplete} className={PRIMARY}>
                                {hasCodes ? 'Complete' : 'Done'}
                            </button>
                        </>
                    )}
                </footer>
            </div>
        </div>
    );
}
