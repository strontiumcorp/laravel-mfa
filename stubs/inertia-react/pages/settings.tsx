// Published by strontiumcorp/laravel-mfa. This file is yours — render it inside
// your app's settings layout. It only wires Inertia to the components in
// components/vendor/laravel-mfa/, which hold the UI.
import MfaFactorCards, { type MfaCardFactor, type MfaFactorType } from '@/components/vendor/laravel-mfa/factor-cards';
import MfaFactorSetupDialog from '@/components/vendor/laravel-mfa/factor-setup-dialog';
import MfaPasswordConfirmForm from '@/components/vendor/laravel-mfa/password-confirm-form';
import MfaRecoveryCodesDialog from '@/components/vendor/laravel-mfa/recovery-codes-dialog';
import MfaRecoveryCodesPanel from '@/components/vendor/laravel-mfa/recovery-codes-panel';
import MfaTrustedBrowsersPanel, { type MfaTrustedBrowser } from '@/components/vendor/laravel-mfa/trusted-browsers-panel';
import { Head, router, useForm } from '@inertiajs/react';
import { type ReactNode, useRef, useState } from 'react';

type Pending = MfaCardFactor & { secret?: string; qr_svg?: string; otpauth_url?: string };

type Props = {
    factors: MfaCardFactor[];
    pending: Pending[];
    availableTypes: { type: MfaFactorType; label: string; recommended: boolean }[];
    recoveryCodesRemaining: number;
    /** How many a fresh set of recovery codes has. */
    recoveryCodesTotal: number;
    /** What the downloaded recovery codes file is named after: the app (as authenticator apps show it) and the account. */
    recoveryCodesFile: { app: string; slug: string; account: string };
    /**
     * New recovery codes waiting to be downloaded and finished in this
     * session: a first method's (`factor`, which is on only after Finish) or
     * a new set for an account that has some (`factor` null; the old ones
     * work until Finish). null = none.
     */
    pendingRecoveryCodes: { codes: string[]; downloaded: boolean; factor: { id: number; type: MfaFactorType } | null } | null;
    retryAfter: number | null;
    /** Seconds until the password prompt may be tried again. */
    passwordRetryAfter: number | null;
    /** Adding or removing a method would ask for the password right now. */
    passwordConfirmationRequired: boolean;
    /**
     * Adding the first method needs proof it's this account, beyond the
     * password: a code emailed to `email` (masked), or, when it's null, a
     * setup link from an administrator. null = not needed.
     */
    enrollmentVerification: { email: string | null } | null;
    mustEnroll: boolean;
    /** What an enforced user must set up; [] = any method. */
    requiredTypes: { type: MfaFactorType; label: string }[];
    /** The nudge's title and body, as a notice for a user with no method who isn't enforced (config mfa.nudge); null otherwise. */
    nudge: { title: string; body: string } | null;
    /** Browsers that skip the challenge, newest first; null = the feature is off. */
    trustedBrowsers: MfaTrustedBrowser[] | null;
    status: string | null;
    urls: {
        store: string;
        confirm: string;
        resend: string;
        destroy: string;
        recoveryCodes: string;
        /** GET: the waiting codes as a file (the server notes the download). */
        downloadRecoveryCodes: string;
        /** POST: saves the waiting codes once downloaded. */
        finishRecoveryCodes: string;
        /** DELETE: drops the waiting codes. */
        discardRecoveryCodes: string;
        confirmPassword: string;
        sendEnrollmentCode: string;
        verifyEnrollmentCode: string;
        /** DELETE; contains __ID__. */
        forgetTrustedBrowser: string;
        /** DELETE: forgets every trusted browser. */
        forgetTrustedBrowsers: string;
    };
};

const withId = (url: string, id: number) => url.replace('__ID__', String(id));

export default function MfaSettings(props: Props) {
    const {
        factors,
        pending,
        availableTypes,
        recoveryCodesRemaining,
        recoveryCodesTotal,
        pendingRecoveryCodes,
        mustEnroll,
        requiredTypes,
        status,
        retryAfter,
        passwordRetryAfter,
        passwordConfirmationRequired,
        enrollmentVerification,
        nudge,
        trustedBrowsers,
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
    // New recovery codes wait in the session until they are downloaded and
    // finished (pendingRecoveryCodes), so a reload shows them again: a first
    // method's in the setup dialog, a new set in its own dialog.
    const methodCodes = pendingRecoveryCodes?.factor ? pendingRecoveryCodes : null;
    const newSet = pendingRecoveryCodes && !pendingRecoveryCodes.factor ? pendingRecoveryCodes : null;
    const [codesOpen, setCodesOpen] = useState(!!newSet);
    const [codesPassword, setCodesPassword] = useState(false);
    // Download: fetched from the server (which notes it, so Finish is allowed) and saved as a file.
    const [downloading, setDownloading] = useState(false);
    const [downloadedNow, setDownloadedNow] = useState(false);
    const [downloadError, setDownloadError] = useState<string | null>(null);
    const downloaded = downloadedNow || !!pendingRecoveryCodes?.downloaded;
    const finishForm = useForm<{ recovery_codes?: string }>({});
    const [forgettingBrowserId, setForgettingBrowserId] = useState<number | null>(null);
    const [forgettingAllBrowsers, setForgettingAllBrowsers] = useState(false);

    // Removing a method may answer "confirm your password first"
    // (routes.password_confirmation): ask for it inside the method's card,
    // then retry the removal.
    type PromptAt = { factor: number };
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

    // Before an account's first method, a code emailed to the account (or an
    // administrator's link) proves it's this account. Both answer under "code".
    const sendEmailCodeForm = useForm<{ code?: string }>({});
    const verifyEmailCodeForm = useForm<{ code?: string }>({});
    // A code went out (kept while the page is open, so reopening the dialog
    // doesn't ask to send another), and the server asked for the check mid-way.
    const [emailCodeSent, setEmailCodeSent] = useState(false);
    const [verifyAgain, setVerifyAgain] = useState(false);
    // The address the code goes to, kept for when the server asks again mid-way.
    const knownVerification = useRef(enrollmentVerification);
    if (enrollmentVerification) knownVerification.current = enrollmentVerification;

    const sendEmailCode = () => {
        verifyEmailCodeForm.clearErrors();
        sendEmailCodeForm.transform(() => ({}));
        sendEmailCodeForm.post(urls.sendEnrollmentCode, { preserveScroll: true, onSuccess: () => setEmailCodeSent(true) });
    };

    const verifyEmailCode = (code: string, then: () => void) => {
        let verified = false;
        sendEmailCodeForm.clearErrors();
        verifyEmailCodeForm.transform(() => ({ code }));
        verifyEmailCodeForm.post(urls.verifyEnrollmentCode, {
            preserveScroll: true,
            onSuccess: () => {
                verified = true;
            },
            // Go on once this visit is over, so the next request doesn't interrupt it.
            onFinish: () => {
                if (verified) then();
            },
        });
    };

    // Adding a method runs in one dialog for every type: password (when
    // needed), then the account check (when needed), then the QR code or the
    // address/number, then the code, then the recovery codes. A setup left pending (e.g. after a reload) reopens it,
    // unless the user closed it ("Continue setup" in its card). A first
    // method whose codes aren't saved yet reopens on its codes, which have
    // no way out but Download and Finish.
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

    // Only a setup that was pending when the page loaded reopens by itself, and only once.
    const [resumeId] = useState(() => pending[0]?.id ?? null);
    const resumable = pending.find((p) => p.id === resumeId && !closedIds.includes(p.id)) ?? null;
    const dialogType = methodCodes?.factor?.type ?? setupType ?? confirming?.type ?? resumable?.type ?? null;
    const current = confirming ?? (dialogType ? (pending.find((p) => p.type === dialogType) ?? null) : null);
    const needsPassword = (passwordConfirmationRequired && !passwordDone) || passwordAgain;
    const needsVerification = !!enrollmentVerification || verifyAgain;
    // Asked before the setup starts, or again when the server says so mid-way.
    const verifyEmail = (!!enrollmentVerification && !current) || verifyAgain ? (enrollmentVerification ?? knownVerification.current ?? { email: null }) : null;

    const store = (type: MfaFactorType, destination?: string) => {
        setLastDestination(destination);
        storeForm.transform(() => (destination === undefined ? { type } : { type, destination }));
        storeForm.post(urls.store, {
            preserveScroll: true,
            onError: (errors) => {
                if (errors.password_confirmation_required) setPasswordAgain(true);
                if (errors.enrollment_verification_required) setVerifyAgain(true);
            },
        });
    };

    const startSetup = (type: MfaFactorType) => {
        // A clean start: no error left over from an earlier attempt.
        storeForm.clearErrors();
        confirmForm.clearErrors();
        resend.clearErrors();
        passwordForm.clearErrors();
        sendEmailCodeForm.clearErrors();
        verifyEmailCodeForm.clearErrors();
        setSetupType(type);
        setClosedIds((ids) => ids.filter((id) => !pending.some((p) => p.id === id && p.type === type)));
        // An authenticator app has nothing to ask first: get its key right away.
        if (type === 'totp' && !needsPassword && !needsVerification && !pending.some((p) => p.type === 'totp')) store('totp');
    };

    // Carry on where the setup was: an authenticator app gets its key, an
    // address or number sent before the server asked for a check is sent again.
    const continueSetup = () => {
        if (!dialogType || current) return;
        if (dialogType === 'totp') store('totp');
        else if (lastDestination !== undefined) store(dialogType, lastDestination);
    };

    const afterPassword = () => {
        setPasswordDone(true);
        setPasswordAgain(false);
        if (!needsVerification) continueSetup();
    };

    const afterVerification = () => {
        setVerifyAgain(false);
        setEmailCodeSent(false);
        continueSetup();
    };

    const endSetup = () => {
        if (current) setClosedIds((ids) => [...ids, current.id]);
        setSetupType(null);
        setConfirming(null);
        setConfirmed(false);
        setPasswordAgain(false);
        setVerifyAgain(false);
        // The next setup asks again if the server says so (passwordConfirmationRequired).
        setPasswordDone(false);
        setLastDestination(undefined);
    };

    const confirmFactor = (p: Pending, code: string) => {
        setConfirming(p);
        setConfirmingId(p.id);
        confirmForm.transform(() => ({ code }));
        confirmForm.post(withId(urls.confirm, p.id), {
            preserveScroll: true,
            onSuccess: () => setConfirmed(true),
            onError: (errors) => {
                if (errors.enrollment_verification_required) setVerifyAgain(true);
            },
        });
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

    const downloadCodes = async () => {
        setDownloading(true);
        setDownloadError(null);
        try {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
            const response = await fetch(`${urls.downloadRecoveryCodes}${tz ? `?tz=${encodeURIComponent(tz)}` : ''}`, {
                credentials: 'same-origin',
                headers: { Accept: 'text/plain' },
            });
            if (response.status === 404) {
                // Expired (mfa.enrollment.ttl): the page starts over.
                setDownloadError('These codes expired. Start again.');
                router.reload();
                return;
            }
            if (!response.ok) throw new Error(String(response.status));
            const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'recovery-codes.txt';
            const url = URL.createObjectURL(await response.blob());
            const a = document.createElement('a');
            a.href = url;
            a.download = name;
            // In the document and revoked a moment later: Firefox cancels a download otherwise.
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            setDownloadedNow(true);
        } catch {
            setDownloadError("Couldn't download the codes. Check your connection and try again.");
        } finally {
            setDownloading(false);
        }
    };

    const forgetDownload = () => {
        setDownloadedNow(false);
        setDownloadError(null);
        finishForm.clearErrors();
    };

    // Saves the downloaded codes: turns the first method on, or replaces the old set.
    const finishCodes = (then: () => void) => {
        finishForm.transform(() => ({}));
        finishForm.post(urls.finishRecoveryCodes, {
            preserveScroll: true,
            onSuccess: () => {
                forgetDownload();
                then();
            },
        });
    };

    const openCodes = () => {
        passwordForm.clearErrors();
        setCodesPassword(false);
        forgetDownload();
        setCodesOpen(true);
    };

    const closeCodes = () => {
        setCodesOpen(false);
        setCodesPassword(false);
        forgetDownload();
    };

    // Closing before Finish drops the new set: the old codes keep working.
    const discardCodes = () => {
        if (newSet) router.delete(urls.discardRecoveryCodes, { preserveScroll: true });
        closeCodes();
    };

    const regenerate = (): void =>
        router.post(urls.recoveryCodes, {}, {
            preserveScroll: true,
            onStart: () => setRegenerating(true),
            onFinish: () => setRegenerating(false),
            onError: (errors) => {
                if (errors.password_confirmation_required) setCodesPassword(true);
            },
        });

    // Forgetting a trusted browser needs no password: the browser only skipped the code.
    const forgetBrowser = (id: number) =>
        router.delete(withId(urls.forgetTrustedBrowser, id), {
            preserveScroll: true,
            onStart: () => setForgettingBrowserId(id),
            onFinish: () => setForgettingBrowserId(null),
        });

    const forgetAllBrowsers = () =>
        router.delete(urls.forgetTrustedBrowsers, {
            preserveScroll: true,
            onStart: () => setForgettingAllBrowsers(true),
            onFinish: () => setForgettingAllBrowsers(false),
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
                    className="min-h-11 w-full rounded-lg bg-[color:var(--mfa-primary,#111827)] px-5 text-sm font-semibold text-[color:var(--mfa-primary-foreground,#ffffff)] sm:w-auto dark:bg-[color:var(--mfa-primary,#f3f4f6)] dark:text-[color:var(--mfa-primary-foreground,#111827)]"
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
                passwordPrompt={retry ? { at: retry.at, node: passwordPrompt } : null}
                onAdd={store}
                onStart={startSetup}
                onRemove={remove}
                adding={storeForm.processing}
                removingId={removingId}
                required={mustEnroll}
                requiredTypes={requiredTypes.map((t) => t.type)}
                notice={nudge}
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
                    verifyEmail={verifyEmail}
                    onSendEmailCode={sendEmailCode}
                    emailCodeSending={sendEmailCodeForm.processing}
                    emailCodeSent={emailCodeSent}
                    emailCodeRetryAfter={retryAfter}
                    onVerifyEmailCode={(code) => verifyEmailCode(code, afterVerification)}
                    emailCodeProcessing={verifyEmailCodeForm.processing}
                    emailCodeError={verifyEmailCodeForm.errors.code ?? sendEmailCodeForm.errors.code}
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
                    confirmed={confirmed || !!methodCodes}
                    recoveryCodes={methodCodes?.codes ?? null}
                    onDownload={downloadCodes}
                    downloading={downloading}
                    downloaded={downloaded}
                    downloadError={downloadError ?? finishForm.errors.recovery_codes}
                    onClose={endSetup}
                    onComplete={methodCodes ? () => finishCodes(endSetup) : endSetup}
                    completing={finishForm.processing}
                />
            )}

            {factors.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">If you lose your device</h2>
                    <MfaRecoveryCodesPanel remaining={recoveryCodesRemaining} total={recoveryCodesTotal} onNewCodes={openCodes} processing={regenerating} />
                </section>
            )}

            <MfaRecoveryCodesDialog
                open={codesOpen}
                remaining={recoveryCodesRemaining}
                total={recoveryCodesTotal}
                onGenerate={regenerate}
                processing={regenerating}
                askPassword={codesPassword}
                onConfirmPassword={(password) =>
                    confirmPassword(password, () => {
                        setCodesPassword(false);
                        regenerate();
                    })
                }
                passwordProcessing={passwordForm.processing}
                passwordError={passwordForm.errors.password}
                passwordRetryAfter={passwordRetryAfter}
                codes={newSet?.codes ?? null}
                onDownload={downloadCodes}
                downloading={downloading}
                downloaded={downloaded}
                downloadError={downloadError ?? finishForm.errors.recovery_codes}
                onClose={discardCodes}
                onComplete={() => finishCodes(closeCodes)}
                completing={finishForm.processing}
            />

            {trustedBrowsers && (
                <section className="space-y-3">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Trusted browsers</h2>
                    <MfaTrustedBrowsersPanel
                        browsers={trustedBrowsers}
                        onForget={forgetBrowser}
                        onForgetAll={forgetAllBrowsers}
                        forgettingId={forgettingBrowserId}
                        forgettingAll={forgettingAllBrowsers}
                    />
                </section>
            )}
        </div>
    );
}
