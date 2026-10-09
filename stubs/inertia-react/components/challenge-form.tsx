// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The verification-code step of the MFA challenge, one method at a time: the
// method's icon and title, a code input with one box per digit, Verify, and
// "Try another way", which lists the other methods (and recovery codes) in the
// same card. Standalone: needs only React and ./icons, imports no other MFA
// file, and knows nothing about Inertia or routes. Pass data and callbacks as
// props. It draws its own card; put it in a narrow column (e.g. max-w-sm).
//
//     <MfaChallengeForm factors={factors} selectedFactorId={id} onSelectFactor={setId} onSubmit={(code) => verify(code)}>
//         <MfaSendCodeButton ... />
//     </MfaChallengeForm>
import { type ClipboardEvent, type ReactNode, type RefObject, type SyntheticEvent, useEffect, useId, useRef, useState } from 'react';
import { MfaFactorIcon, MfaIconChevronLeft, MfaIconChevronRight, MfaIconKey, type MfaFactorType } from './icons';

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
    /** Email/SMS: seconds until the code that is out stops working, when the server tells. */
    expires_in?: number | null;
    /** Digits in this method's codes (one box each); defaults to 6. */
    code_length?: number;
};

export type MfaChallengeFormProps = {
    factors: MfaChallengeFactor[];
    selectedFactorId: number | null;
    onSelectFactor: (id: number) => void;
    /** Called with the digits the user entered. */
    onSubmit: (code: string) => void;
    processing?: boolean;
    error?: string | null;
    /** Rendered under the code input, e.g. the resend line for email and SMS. */
    children?: ReactNode;
    /** Adds "Recovery code" to the "Try another way" list. */
    onUseRecoveryCode?: () => void;
    /** Shows "Sign out" when given. */
    onSignOut?: () => void;
    /** Email/SMS: a code is out ("we sent a code to …"). Defaults to the factor's code_sent. */
    sent?: boolean;
    /** Email/SMS: the last send failed ("we couldn't send a code to …"; show the error in children). */
    sendFailed?: boolean;
    /** Email/SMS: the code that was out has expired ("the code we sent to … has expired"). */
    expired?: boolean;
    /** Email/SMS: no code is out and a new one has to wait, because one was just used ("you recently used a code sent to …"). */
    waiting?: boolean;
    /** Open on the "Try another way" list, e.g. when coming back from the recovery code form. */
    initialView?: 'code' | 'methods';
};

const CARD = 'w-full rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900';
const PRIMARY = 'min-h-11 w-full rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900';
const QUIET =
    'inline-flex min-h-11 items-center gap-1 rounded-lg px-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100';

const TILE: Record<MfaFactorType, string> = {
    totp: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
    email: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    sms: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
};
const RECOVERY_TILE = 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';

const NAME: Record<MfaFactorType, string> = { totp: 'Authenticator app', email: 'Email', sms: 'Text message' };
const TITLE: Record<MfaFactorType, string> = { totp: 'Open your authenticator app', email: 'Check your email', sms: 'Check your phone' };

/** The code input: one real input over one box per digit, so autofill, paste and screen readers see a normal field. */
function CodeBoxes({
    value,
    onChange,
    length,
    invalid,
    input,
    describedBy,
}: {
    value: string;
    onChange: (code: string) => void;
    length: number;
    invalid: boolean;
    input: RefObject<HTMLInputElement | null>;
    describedBy: string;
}) {
    const [focused, setFocused] = useState(false);
    const [allSelected, setAllSelected] = useState(false);
    const active = Math.min(value.length, length - 1);

    // Boxes fill left to right, so keep the caret at the end, unless the whole
    // code is selected (after a failure, or select-all): then typing replaces it.
    const onSelect = (e: SyntheticEvent<HTMLInputElement>) => {
        const el = e.currentTarget;
        const end = el.value.length;
        const all = end > 0 && el.selectionStart === 0 && el.selectionEnd === end;
        setAllSelected(all);
        if (!all && (el.selectionStart !== end || el.selectionEnd !== end)) el.setSelectionRange(end, end);
    };

    // A pasted code replaces what's there, whatever surrounds the digits.
    const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
        e.preventDefault();
        onChange(e.clipboardData.getData('text').replace(/\D/g, '').slice(0, length));
    };

    return (
        <div className="relative">
            <div aria-hidden className="flex gap-2">
                {Array.from({ length }, (_, i) => {
                    const digit = value[i] ?? '';
                    const current = focused && !allSelected && i === active;
                    const border = invalid
                        ? 'border-red-500 dark:border-red-500'
                        : current || (focused && allSelected)
                          ? 'border-gray-900 ring-2 ring-gray-900/10 dark:border-gray-100 dark:ring-gray-100/20'
                          : digit
                            ? 'border-gray-400 dark:border-gray-600'
                            : 'border-gray-300 dark:border-gray-700';

                    return (
                        <span
                            key={i}
                            data-box
                            className={`flex h-12 min-w-0 flex-1 items-center justify-center rounded-lg border font-mono text-xl font-semibold text-gray-900 sm:h-14 sm:text-2xl dark:text-gray-100 ${border} ${
                                focused && allSelected && digit ? 'bg-gray-200 dark:bg-gray-700' : 'bg-white dark:bg-gray-950'
                            }`}
                        >
                            {digit || (current && <span className="h-6 w-px animate-pulse bg-gray-900 dark:bg-gray-100" />)}
                        </span>
                    );
                })}
            </div>
            {/* Invisible: the boxes above draw the code. Every border, padding, shadow and ring a host's form
                styles could add is reset, or it shows as a frame around the row. */}
            <input
                ref={input}
                type="text"
                aria-label="Verification code"
                aria-describedby={describedBy}
                aria-invalid={invalid || undefined}
                value={value}
                onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, length))}
                onPaste={onPaste}
                onSelect={onSelect}
                onFocus={() => setFocused(true)}
                onBlur={() => setFocused(false)}
                inputMode="numeric"
                autoComplete="one-time-code"
                pattern={`\\d{${length}}`}
                maxLength={length}
                autoFocus
                className="absolute inset-0 m-0 h-full w-full cursor-text appearance-none rounded-none border-0 bg-transparent p-0 text-base text-transparent caret-transparent shadow-none outline-none ring-0 selection:bg-transparent focus:border-0 focus:shadow-none focus:outline-none focus:ring-0 focus:ring-offset-0"
            />
        </div>
    );
}

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
    sent,
    sendFailed = false,
    expired = false,
    waiting = false,
    initialView = 'code',
}: MfaChallengeFormProps) {
    const [code, setCode] = useState('');
    const [edited, setEdited] = useState(false);
    const factor = factors.find((f) => f.id === selectedFactorId) ?? null;
    const [view, setView] = useState<'code' | 'methods'>(factor ? initialView : 'methods');
    const titleId = useId();
    const canSwitch = factors.length > 1 || Boolean(onUseRecoveryCode);
    const length = factor?.code_length ?? 6;

    // A new factor means a new code, typed from the first box.
    const input = useRef<HTMLInputElement>(null);
    const firstRender = useRef(true);
    useEffect(() => {
        setCode('');
        if (!firstRender.current) input.current?.focus();
        firstRender.current = false;
    }, [selectedFactorId]);

    // After a failed attempt, keep the code so the user sees what they typed,
    // and select it so typing straight away replaces it.
    const wasProcessing = useRef(processing);
    useEffect(() => {
        if (wasProcessing.current && !processing && error) {
            setEdited(false);
            input.current?.focus();
            input.current?.select();
        }
        wasProcessing.current = processing;
    }, [processing, error]);

    // The list replaces the code view: put focus on its heading. (Back to the
    // code view, the input takes focus itself.)
    const listHeading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        if (view === 'methods') listHeading.current?.focus();
    }, [view]);

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        if (code.length === length && !processing) onSubmit(code);
    };

    const pick = (id: number) => {
        setView('code');
        if (id !== selectedFactorId) onSelectFactor(id);
    };

    const footer = (left: ReactNode) =>
        (left || onSignOut) && (
            <div className="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-1.5 sm:px-6 dark:border-gray-800">
                {left || <span />}
                {onSignOut && (
                    <button type="button" onClick={onSignOut} className={QUIET}>
                        Sign out
                    </button>
                )}
            </div>
        );

    if (view === 'methods' || !factor) {
        return (
            <div className={CARD}>
                <div className="px-6 pb-6 pt-4 sm:px-8 sm:pb-8">
                    {factor ? (
                        <button type="button" onClick={() => setView('code')} className={`${QUIET} -ml-2`}>
                            <MfaIconChevronLeft size={18} />
                            Back
                        </button>
                    ) : (
                        <div className="h-4" />
                    )}
                    <h2 ref={listHeading} tabIndex={-1} id={titleId} className="mt-2 text-lg font-semibold text-gray-900 outline-none dark:text-gray-100">
                        Choose how to verify
                    </h2>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Use any method you've set up.</p>

                    <ul aria-labelledby={titleId} className="mt-5 space-y-2">
                        {factors.map((f) => (
                            <li key={f.id}>
                                <MethodRow
                                    tile={TILE[f.type]}
                                    icon={<MfaFactorIcon type={f.type} size={18} />}
                                    name={NAME[f.type] ?? f.type_label}
                                    detail={f.type === 'totp' ? 'Code from your app' : (f.destination ?? f.type_label)}
                                    current={f.id === selectedFactorId}
                                    onClick={() => pick(f.id)}
                                />
                            </li>
                        ))}
                        {onUseRecoveryCode && (
                            <li>
                                <MethodRow tile={RECOVERY_TILE} icon={<MfaIconKey size={18} />} name="Recovery code" detail="One of the codes you saved" onClick={onUseRecoveryCode} />
                            </li>
                        )}
                    </ul>
                </div>
                {footer(null)}
            </div>
        );
    }

    const where = factor.destination ?? (factor.type === 'email' ? 'your email' : 'your phone');
    const codeSent = sent ?? factor.code_sent ?? false;
    const strong = <span className="font-medium text-gray-900 dark:text-gray-100">{where}</span>;
    const description =
        factor.type === 'totp' ? (
            `Enter the ${length}-digit code from your authenticator app.`
        ) : codeSent ? (
            <>
                Enter the {length}-digit code we sent to {strong}.
            </>
        ) : sendFailed ? (
            <>We couldn't send a code to {strong}.</>
        ) : waiting ? (
            <>You recently used a code sent to {strong}.</>
        ) : expired ? (
            <>The code we sent to {strong} has expired.</>
        ) : (
            <>We're sending a code to {strong}.</>
        );

    return (
        <div className={CARD}>
            <div className="px-6 pb-6 pt-7 sm:px-8 sm:pb-8 sm:pt-8">
                <div className={`mx-auto flex size-12 items-center justify-center rounded-xl ${TILE[factor.type]}`}>
                    <MfaFactorIcon type={factor.type} size={24} />
                </div>
                <h2 id={titleId} className="mt-4 text-center text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {TITLE[factor.type] ?? factor.type_label}
                </h2>
                <p id={`${titleId}-description`} aria-live="polite" className="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">
                    {description}
                </p>

                <form onSubmit={submit} aria-labelledby={titleId} className="mt-6 space-y-4">
                    <CodeBoxes
                        value={code}
                        onChange={(c) => {
                            setCode(c);
                            setEdited(true);
                        }}
                        length={length}
                        invalid={Boolean(error) && !edited}
                        input={input}
                        describedBy={`${titleId}-description`}
                    />
                    {error && (
                        <p role="alert" className="text-center text-sm text-red-600 dark:text-red-400">
                            {error}
                        </p>
                    )}
                    {children}
                    <button type="submit" disabled={processing || code.length < length} className={PRIMARY}>
                        Verify
                    </button>
                </form>
            </div>
            {footer(
                canSwitch && (
                    <button type="button" onClick={() => setView('methods')} className={QUIET}>
                        Try another way
                    </button>
                ),
            )}
        </div>
    );
}

function MethodRow({ tile, icon, name, detail, current = false, onClick }: { tile: string; icon: ReactNode; name: string; detail: string; current?: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={current || undefined}
            className="flex min-h-14 w-full items-center gap-3 rounded-xl border border-gray-200 px-3 py-2.5 text-left hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/60"
        >
            <span className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${tile}`}>{icon}</span>
            <span className="min-w-0 grow">
                <span className="block text-sm font-medium text-gray-900 dark:text-gray-100">{name}</span>
                <span className="block truncate text-sm text-gray-500 dark:text-gray-400">{detail}</span>
            </span>
            <MfaIconChevronRight size={18} className="shrink-0 text-gray-400 dark:text-gray-500" />
        </button>
    );
}
