// Published by strontiumcorp/laravel-mfa. This file is yours — render it inside
// your app's settings layout. It only wires Inertia to the components in
// components/vendor/laravel-mfa/, which hold the UI.
import MfaFactorCards, { type MfaCardFactor, type MfaFactorType } from '@/components/vendor/laravel-mfa/factor-cards';
import MfaFactorSetupDialog from '@/components/vendor/laravel-mfa/factor-setup-dialog';
import MfaPasswordConfirmForm from '@/components/vendor/laravel-mfa/password-confirm-form';
import MfaRecoveryCodesPanel from '@/components/vendor/laravel-mfa/recovery-codes-panel';
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
    /** Adding or removing a method would ask for the password right now. */
    passwordConfirmationRequired: boolean;
    mustEnroll: boolean;
    /** What an enforced user must set up; [] = any method. */
    requiredTypes: { type: MfaFactorType; label: string }[];
    status: string | null;
    urls: { store: string; confirm: string; resend: string; destroy: string; recoveryCodes: string; confirmPassword: string };
};

const withId = (url: string, id: number) => url.replace('__ID__', String(id));

export default function MfaSettings(props: Props) {
    const {
        factors,
        pending,
        availableTypes,
        recoveryCodesRemaining,
        recoveryCodesTotal,
        recoveryCodes,
        mustEnroll,
        requiredTypes,
        status,
        retryAfter,
        passwordRetryAfter,
        passwordConfirmationRequired,
        urls,
    } = props;

    // The components hold the inputs; transform() adds them when posting.
    // Failures come back under "code" (confirm, resend) or "destination"/"type" (store).
    const storeForm = useForm<{ type?: string; destination?: string }>({});
    const confirmForm = useForm<{ code?: string }>({});
    const resend = useForm<{ code?: string }>({});
    // Which pending factor the last confirm/resend was for, so its error shows there.
    const [confirmingId, setConfirmingId] = useState<number | null>(null);
    const [resendingId, setResendingId] = useState<number | null>(null);
    const [removingId, setRemovingId] = useState<number | null>(null);
    const [regenerating, setRegenerating] = useState(false);

    // Removing a method and new recovery codes may answer "confirm your
    // password first" (routes.password_confirmation): ask for it inside the
    // card where the change started, then retry the change.
    type PromptAt = { factor: number } | 'recovery';
    const passwordForm = useForm<{ password?: string }>({});
    const [retry, setRetry] = useState<{ action: () => void; at: PromptAt } | null>(null);
    const askPasswordFor = (at: PromptAt, action: () => void) => (errors: Record<string, string>) => {
        if (errors.password_confirmation_required) setRetry({ action, at });
    };

    const confirmPassword = (password: string, then: () => void) => {
        let confirmed = false;
        passwordForm.transform(() => ({ password }));
        passwordForm.post(urls.confirmPassword, {
            preserveScroll: true,
            onSuccess: () => {
                confirmed = true;
            },
            // Go on once this visit is over, so the next request doesn't interrupt it.
            onFinish: () => {
                if (confirmed) then();
            },
        });
    };

    // Adding a method runs in one dialog for every type: password (when
    // needed), then the QR code or the address/number, then the code, then the
    // recovery codes. A setup left pending (e.g. after a reload) reopens it,
    // unless the user closed it ("Continue setup" in its card).
    const [setupType, setSetupType] = useState<MfaFactorType | null>(null);
    const [closedIds, setClosedIds] = useState<number[]>([]);
    // The pending setup being confirmed: confirming removes it from the props.
    const [confirming, setConfirming] = useState<Pending | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    // Password: confirmed in this dialog, or asked again by the server mid-way (it expired).
    const [passwordDone, setPasswordDone] = useState(false);
    const [passwordAgain, setPasswordAgain] = useState(false);
    // The address or number last sent, to retry it after the password.
    const [lastDestination, setLastDestination] = useState<string | undefined>(undefined);
    // Codes the dialog already showed, so the panel doesn't show them again.
    const [shownCodes, setShownCodes] = useState<string[] | null>(null);

    // Only a setup that was pending when the page loaded reopens by itself, and only once.
    const [resumeId] = useState(() => pending[0]?.id ?? null);
    const resumable = pending.find((p) => p.id === resumeId && !closedIds.includes(p.id)) ?? null;
    const dialogType = setupType ?? confirming?.type ?? resumable?.type ?? null;
    const current = confirming ?? (dialogType ? (pending.find((p) => p.type === dialogType) ?? null) : null);
    const needsPassword = (passwordConfirmationRequired && !passwordDone) || passwordAgain;

    const store = (type: MfaFactorType, destination?: string) => {
        setLastDestination(destination);
        storeForm.transform(() => (destination === undefined ? { type } : { type, destination }));
        storeForm.post(urls.store, {
            preserveScroll: true,
            onError: (errors) => {
                if (errors.password_confirmation_required) setPasswordAgain(true);
            },
        });
    };

    const startSetup = (type: MfaFactorType) => {
        // A clean start: no error left over from an earlier attempt.
        storeForm.clearErrors();
        confirmForm.clearErrors();
        resend.clearErrors();
        passwordForm.clearErrors();
        setSetupType(type);
        setClosedIds((ids) => ids.filter((id) => !pending.some((p) => p.id === id && p.type === type)));
        // An authenticator app has nothing to ask first: get its key right away.
        if (type === 'totp' && !needsPassword && !pending.some((p) => p.type === 'totp')) store('totp');
    };

    const afterPassword = () => {
        setPasswordDone(true);
        setPasswordAgain(false);
        if (!dialogType || current) return;
        if (dialogType === 'totp') store('totp');
        else if (lastDestination !== undefined) store(dialogType, lastDestination);
    };

    const endSetup = (complete: boolean) => {
        if (current) setClosedIds((ids) => [...ids, current.id]);
        if (complete) setShownCodes(recoveryCodes);
        setSetupType(null);
        setConfirming(null);
        setConfirmed(false);
        setPasswordAgain(false);
        // The next setup asks again if the server says so (passwordConfirmationRequired).
        setPasswordDone(false);
        setLastDestination(undefined);
    };

    const confirmFactor = (p: Pending, code: string) => {
        setConfirming(p);
        setConfirmingId(p.id);
        confirmForm.transform(() => ({ code }));
        confirmForm.post(withId(urls.confirm, p.id), { preserveScroll: true, onSuccess: () => setConfirmed(true) });
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

    // A setup the user closed stays in its card, to pick up again.
    const setups: Partial<Record<MfaFactorType, ReactNode>> = {};
    for (const p of pending) {
        setups[p.type] = (
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-gray-600 dark:text-gray-400">Finish adding it in the setup window.</p>
                <button
                    type="button"
                    onClick={() => startSetup(p.type)}
                    className="min-h-11 w-full rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white sm:w-auto dark:bg-gray-100 dark:text-gray-900"
                >
                    Continue setup
                </button>
            </div>
        );
    }

    const passwordPrompt = retry && (
        <MfaPasswordConfirmForm
            framed={false}
            onConfirm={(password) =>
                confirmPassword(password, () => {
                    setRetry(null);
                    retry.action();
                })
            }
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
                onAdd={store}
                onStart={startSetup}
                onRemove={remove}
                adding={storeForm.processing}
                removingId={removingId}
                required={mustEnroll}
                requiredTypes={requiredTypes.map((t) => t.type)}
            />

            {dialogType && (
                <MfaFactorSetupDialog
                    open
                    type={dialogType}
                    label={availableTypes.find((t) => t.type === dialogType)?.label ?? current?.type_label ?? ''}
                    askPassword={needsPassword && !current}
                    onConfirmPassword={(password) => confirmPassword(password, afterPassword)}
                    passwordProcessing={passwordForm.processing}
                    passwordError={passwordForm.errors.password}
                    passwordRetryAfter={passwordRetryAfter}
                    onSubmitDestination={(destination) => store(dialogType, destination)}
                    destinationProcessing={storeForm.processing}
                    destinationError={storeForm.errors.destination ?? storeForm.errors.type}
                    secret={current?.secret}
                    qrSvg={current?.qr_svg}
                    otpauthUrl={current?.otpauth_url}
                    sentTo={dialogType === 'totp' ? null : current?.destination}
                    onResend={() => current && resendCode(current.id)}
                    resending={resend.processing}
                    retryAfter={retryAfter}
                    onConfirm={(code) => current && confirmFactor(current, code)}
                    processing={confirmForm.processing}
                    error={(current && confirmingId === current.id ? confirmForm.errors.code : null) ?? (current && resendingId === current.id ? resend.errors.code : null)}
                    confirmed={confirmed}
                    recoveryCodes={confirmed ? recoveryCodes : null}
                    onClose={() => endSetup(false)}
                    onComplete={() => endSetup(true)}
                />
            )}

            {(factors.length > 0 || recoveryCodes) && (
                <section className="space-y-3">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">If you lose your device</h2>
                    <MfaRecoveryCodesPanel
                        remaining={recoveryCodesRemaining}
                        total={recoveryCodesTotal}
                        codes={dialogType || recoveryCodes === shownCodes ? null : recoveryCodes}
                        onRegenerate={regenerate}
                        processing={regenerating}
                        passwordPrompt={retry?.at === 'recovery' ? passwordPrompt : null}
                    />
                </section>
            )}
        </div>
    );
}
