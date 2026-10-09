// Published by strontiumcorp/laravel-mfa. This file is yours — render it inside
// your app's settings layout. It only wires Inertia to the components in
// components/vendor/laravel-mfa/, which hold the UI.
import MfaDestinationSetup from '@/components/vendor/laravel-mfa/destination-setup';
import MfaFactorCards, { type MfaCardFactor, type MfaFactorType } from '@/components/vendor/laravel-mfa/factor-cards';
import MfaPasswordConfirmForm from '@/components/vendor/laravel-mfa/password-confirm-form';
import MfaRecoveryCodesPanel from '@/components/vendor/laravel-mfa/recovery-codes-panel';
import MfaTotpSetupDialog from '@/components/vendor/laravel-mfa/totp-setup-dialog';
import { Head, router, useForm } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

type Pending = MfaCardFactor & { secret?: string; qr_svg?: string; otpauth_url?: string };

type Props = {
    factors: MfaCardFactor[];
    pending: Pending[];
    availableTypes: { type: MfaFactorType; label: string; recommended: boolean }[];
    recoveryCodesRemaining: number;
    /** How many a fresh set of recovery codes has. */
    recoveryCodesTotal: number;
    recoveryCodes: string[] | null;
    retryAfter: number | null;
    /** Seconds until the password prompt may be tried again. */
    passwordRetryAfter: number | null;
    mustEnroll: boolean;
    /** What an enforced user must set up; [] = any method. */
    requiredTypes: { type: MfaFactorType; label: string }[];
    status: string | null;
    urls: { store: string; confirm: string; resend: string; destroy: string; recoveryCodes: string; confirmPassword: string };
};

const withId = (url: string, id: number) => url.replace('__ID__', String(id));

export default function MfaSettings(props: Props) {
    const { factors, pending, availableTypes, recoveryCodesRemaining, recoveryCodesTotal, recoveryCodes, mustEnroll, requiredTypes, status, retryAfter, passwordRetryAfter, urls } =
        props;

    // The components hold the inputs; transform() adds them when posting.
    // Failures come back under "code" (confirm, resend) or "destination"/"type" (store).
    const store = useForm<{ type?: string; destination?: string }>({});
    const confirmForm = useForm<{ code?: string }>({});
    const resend = useForm<{ code?: string }>({});
    // Which pending factor the last confirm/resend was for, so its error shows there.
    const [confirmingId, setConfirmingId] = useState<number | null>(null);
    const [resendingId, setResendingId] = useState<number | null>(null);
    const [removingId, setRemovingId] = useState<number | null>(null);
    const [regenerating, setRegenerating] = useState(false);

    // Adding or removing a factor and new recovery codes may answer "confirm
    // your password first" (routes.password_confirmation): ask for it inside
    // the card where the change started, then retry the change.
    type PromptAt = { factor: number } | { type: MfaFactorType } | 'recovery';
    const passwordForm = useForm<{ password?: string }>({});
    const [retry, setRetry] = useState<{ action: () => void; at: PromptAt } | null>(null);
    const askPasswordFor = (at: PromptAt, action: () => void) => (errors: Record<string, string>) => {
        if (errors.password_confirmation_required) setRetry({ action, at });
    };

    const confirmPassword = (password: string) => {
        let confirmed = false;
        passwordForm.transform(() => ({ password }));
        passwordForm.post(urls.confirmPassword, {
            preserveScroll: true,
            onSuccess: () => {
                confirmed = true;
            },
            // Retry once this visit is over, so the retry doesn't interrupt it.
            onFinish: () => {
                if (!confirmed) return;
                setRetry(null);
                retry?.action();
            },
        });
    };

    const add = (type: MfaFactorType, destination?: string) => {
        store.transform(() => (destination === undefined ? { type } : { type, destination }));
        store.post(urls.store, { preserveScroll: true, onError: askPasswordFor({ type }, () => add(type, destination)) });
    };

    const confirmFactor = (id: number, code: string, onSuccess?: () => void) => {
        setConfirmingId(id);
        confirmForm.transform(() => ({ code }));
        confirmForm.post(withId(urls.confirm, id), { preserveScroll: true, onSuccess });
    };

    // An authenticator app is set up in a dialog: scan, enter the code, save the
    // recovery codes. It opens for a pending one unless the user closed it; it
    // keeps a copy of the pending setup, because confirming removes it from the props.
    const pendingTotp = pending.find((p) => p.type === 'totp') ?? null;
    const [dialog, setDialog] = useState<Pending | null>(null);
    const [closedId, setClosedId] = useState<number | null>(null);
    const [totpConfirmed, setTotpConfirmed] = useState(false);
    // Codes the dialog already showed, so the panel doesn't show them again.
    const [shownCodes, setShownCodes] = useState<string[] | null>(null);
    const totp = dialog ?? (pendingTotp && pendingTotp.id !== closedId ? pendingTotp : null);

    const closeDialog = () => {
        if (totp) setClosedId(totp.id);
        setDialog(null);
    };

    const completeDialog = () => {
        if (totp) setClosedId(totp.id);
        setShownCodes(recoveryCodes);
        setDialog(null);
        setTotpConfirmed(false);
    };

    const resendCode = (id: number) => {
        setResendingId(id);
        resend.transform(() => ({}));
        resend.post(withId(urls.resend, id), { preserveScroll: true });
    };

    const remove = (factor: MfaCardFactor) =>
        router.delete(withId(urls.destroy, factor.id), {
            preserveScroll: true,
            onStart: () => setRemovingId(factor.id),
            onFinish: () => setRemovingId(null),
            onError: askPasswordFor({ factor: factor.id }, () => remove(factor)),
        });

    const regenerate = (): void =>
        router.post(urls.recoveryCodes, {}, {
            preserveScroll: true,
            onStart: () => setRegenerating(true),
            onFinish: () => setRegenerating(false),
            onError: askPasswordFor('recovery', regenerate),
        });

    // Each setup in progress goes inside its method's card.
    const setups: Partial<Record<MfaFactorType, ReactNode>> = {};
    for (const p of pending) {
        setups[p.type] =
            p.type === 'totp' ? (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-gray-600 dark:text-gray-400">Finish adding it in the setup window.</p>
                    <button
                        type="button"
                        onClick={() => setClosedId(null)}
                        className="min-h-11 w-full rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white sm:w-auto dark:bg-gray-100 dark:text-gray-900"
                    >
                        Continue setup
                    </button>
                </div>
            ) : (
                <MfaDestinationSetup
                    framed={false}
                    label={p.type_label}
                    destination={p.destination}
                    onConfirm={(code) => confirmFactor(p.id, code)}
                    onResend={() => resendCode(p.id)}
                    processing={confirmForm.processing && confirmingId === p.id}
                    resending={resend.processing && resendingId === p.id}
                    retryAfter={retryAfter}
                    sent={status === 'code-sent' && resendingId === p.id}
                    error={(confirmingId === p.id ? confirmForm.errors.code : null) ?? (resendingId === p.id ? resend.errors.code : null)}
                />
            );
    }

    const passwordPrompt = retry && (
        <MfaPasswordConfirmForm
            framed={false}
            onConfirm={confirmPassword}
            onCancel={() => setRetry(null)}
            processing={passwordForm.processing}
            error={passwordForm.errors.password}
            retryAfter={passwordRetryAfter}
        />
    );

    // "Required" wins: an enforced user with only other methods is still held here.
    const state = mustEnroll
        ? { text: 'Required', className: 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200' }
        : factors.length > 0
          ? { text: 'On', className: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' }
          : { text: 'Off', className: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' };

    return (
        <div className="mx-auto max-w-3xl space-y-8 px-4 py-10">
            <Head title="Two-factor authentication" />

            <header className="flex items-start justify-between gap-6">
                <div className="space-y-1.5">
                    <h1 className="text-2xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Two-factor authentication</h1>
                    <p className="text-[0.9375rem] text-gray-600 dark:text-gray-400">A second step at sign-in, so a stolen password isn't enough.</p>
                </div>
                <span className={`shrink-0 rounded-full px-3 py-1.5 text-sm font-medium ${state.className}`}>{state.text}</span>
            </header>

            <MfaFactorCards
                types={availableTypes}
                factors={factors}
                setups={setups}
                passwordPrompt={retry && retry.at !== 'recovery' ? { at: retry.at, node: passwordPrompt } : null}
                onAdd={add}
                onRemove={remove}
                adding={store.processing}
                error={store.errors.destination ?? store.errors.type}
                removingId={removingId}
                required={mustEnroll}
                requiredTypes={requiredTypes.map((t) => t.type)}
            />

            {totp && (
                <MfaTotpSetupDialog
                    open
                    secret={totp.secret ?? ''}
                    qrSvg={totp.qr_svg}
                    otpauthUrl={totp.otpauth_url}
                    onConfirm={(code) => {
                        setDialog(totp);
                        confirmFactor(totp.id, code, () => setTotpConfirmed(true));
                    }}
                    processing={confirmForm.processing && confirmingId === totp.id}
                    error={confirmingId === totp.id ? confirmForm.errors.code : null}
                    confirmed={totpConfirmed}
                    recoveryCodes={totpConfirmed ? recoveryCodes : null}
                    onClose={closeDialog}
                    onComplete={completeDialog}
                />
            )}

            {(factors.length > 0 || recoveryCodes) && (
                <section className="space-y-3">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">If you lose your device</h2>
                    <MfaRecoveryCodesPanel
                        remaining={recoveryCodesRemaining}
                        total={recoveryCodesTotal}
                        codes={totp || recoveryCodes === shownCodes ? null : recoveryCodes}
                        onRegenerate={regenerate}
                        processing={regenerating}
                        passwordPrompt={retry?.at === 'recovery' ? passwordPrompt : null}
                    />
                </section>
            )}
        </div>
    );
}
