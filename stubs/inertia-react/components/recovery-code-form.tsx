// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The recovery-code step of the MFA challenge, in the same card as the code
// step. Standalone: needs only React and ./icons, imports no other MFA file,
// and knows nothing about Inertia or routes.
//
//     <MfaRecoveryCodeForm onSubmit={(code) => recover(code)} processing={processing} error={errors.code} onTryAnotherWay={back} />
import { useId, useLayoutEffect, useRef, useState } from 'react';
import { MfaIconKey } from './icons';

export type MfaRecoveryCodeFormProps = {
    /** Called with the code as typed (the server normalises it). */
    onSubmit: (code: string) => void;
    processing?: boolean;
    error?: string | null;
    /** Shows "Try another way" when given (back to the list of methods). */
    onTryAnotherWay?: () => void;
    /** Shows "Use a verification code" when given and onTryAnotherWay isn't. */
    onUseVerificationCode?: () => void;
    /** Shows "Sign out" when given. */
    onSignOut?: () => void;
};

const CARD = 'w-full rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900';
const PRIMARY = 'min-h-11 w-full rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900';
const QUIET =
    'inline-flex min-h-11 items-center rounded-lg px-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100';

// Text fields set every part of their look (type, border, padding, colours, focus ring), so a host's
// form styles (@tailwindcss/forms, a global `input {}` rule) can't change them.
// The border colour depends on the error, so it's not part of FIELD (two colour classes would clash).
const FIELD =
    'appearance-none rounded-lg border bg-white text-gray-900 shadow-none placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:ring-offset-0 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-600 dark:focus:ring-gray-100/20';
const FIELD_BORDER = 'border-gray-300 focus:border-gray-900 dark:border-gray-700 dark:focus:border-gray-100';
const FIELD_BORDER_ERROR = 'border-red-500 focus:border-red-500 dark:border-red-500 dark:focus:border-red-500';

// A whole code on its own: groups joined by dashes ("ab3de-fg7hk"), or a long enough run of letters and digits.
const ONE_CODE = /^(?:[a-z0-9]+(?:-[a-z0-9]+)+|[a-z0-9]{8,})$/i;

// The first code, when the text holds several (lines pasted from the saved
// list, or codes separated by spaces or commas); null otherwise, so a single
// code typed in groups ("ab3de fg7hk") stays as typed. The server answers
// "Enter one recovery code" to the same input.
function firstOfSeveral(text: string): string | null {
    const codes = text.split(/[\s,;]+/).filter((token) => ONE_CODE.test(token));

    return codes.length > 1 ? codes[0] : null;
}

export default function MfaRecoveryCodeForm({ onSubmit, processing = false, error = null, onTryAnotherWay, onUseVerificationCode, onSignOut }: MfaRecoveryCodeFormProps) {
    const [code, setCode] = useState('');
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    // Where the caret goes after a paste was cut down to one code.
    const caret = useRef<number | null>(null);
    useLayoutEffect(() => {
        if (caret.current !== null) {
            input.current?.setSelectionRange(caret.current, caret.current);
            caret.current = null;
        }
    }, [code]);

    // A text field drops line breaks, so a pasted list arrives as one long
    // word: look at the clipboard text before it does.
    const paste = (e: { clipboardData: DataTransfer; currentTarget: HTMLInputElement; preventDefault(): void }) => {
        const first = firstOfSeveral(e.clipboardData.getData('text'));
        if (first === null) return;

        e.preventDefault();
        const { value, selectionStart, selectionEnd } = e.currentTarget;
        const start = selectionStart ?? value.length;
        caret.current = start + first.length;
        setCode(value.slice(0, start) + first + value.slice(selectionEnd ?? start));
    };
    const back = onTryAnotherWay ? (
        <button type="button" onClick={onTryAnotherWay} className={QUIET}>
            Try another way
        </button>
    ) : onUseVerificationCode ? (
        <button type="button" onClick={onUseVerificationCode} className={QUIET}>
            Use a verification code
        </button>
    ) : null;

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        onSubmit(code.trim());
    };

    return (
        <div className={CARD}>
            <div className="px-6 pb-6 pt-7 sm:px-8 sm:pb-8 sm:pt-8">
                <div className="mx-auto flex size-12 items-center justify-center rounded-xl bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <MfaIconKey size={24} />
                </div>
                <h2 id={id} className="mt-4 text-center text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Use a recovery code
                </h2>
                <p id={`${id}-description`} className="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">
                    Enter one code from your saved list, like k7m2p-x1q0t. Each code works once.
                </p>

                <form onSubmit={submit} aria-labelledby={id} className="mt-6 space-y-4">
                    <input
                        type="text"
                        aria-label="Recovery code"
                        aria-describedby={`${id}-description`}
                        aria-invalid={error ? true : undefined}
                        ref={input}
                        value={code}
                        onPaste={paste}
                        onChange={(e) => setCode(firstOfSeveral(e.target.value) ?? e.target.value)}
                        placeholder="xxxxx-xxxxx"
                        autoComplete="off"
                        autoCapitalize="none"
                        spellCheck={false}
                        autoFocus
                        className={`${FIELD} min-h-12 w-full px-3 py-0 text-center font-mono text-base tracking-wider ${
                            error ? FIELD_BORDER_ERROR : FIELD_BORDER
                        }`}
                    />
                    {error && (
                        <p role="alert" className="text-center text-sm text-red-600 dark:text-red-400">
                            {error}
                        </p>
                    )}
                    <button type="submit" disabled={processing || code.trim() === ''} className={PRIMARY}>
                        Verify
                    </button>
                </form>
            </div>

            {(back || onSignOut) && (
                <div className="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-1.5 sm:px-6 dark:border-gray-800">
                    {back ?? <span />}
                    {onSignOut && (
                        <button type="button" onClick={onSignOut} className={QUIET}>
                            Sign out
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
