// Published by strontiumcorp/laravel-mfa. This file is yours — render it inside
// your app's settings layout. It only wires Inertia to the components in
// components/vendor/laravel-mfa/, which hold the UI.
import MfaAddFactorForm, { type MfaEnrollableType } from '@/components/vendor/laravel-mfa/add-factor-form';
import MfaDestinationSetup from '@/components/vendor/laravel-mfa/destination-setup';
import MfaFactorList, { type MfaListedFactor } from '@/components/vendor/laravel-mfa/factor-list';
import MfaPasswordConfirmForm from '@/components/vendor/laravel-mfa/password-confirm-form';
import MfaRecoveryCodesPanel from '@/components/vendor/laravel-mfa/recovery-codes-panel';
import MfaTotpSetup from '@/components/vendor/laravel-mfa/totp-setup';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Pending = MfaListedFactor & { secret?: string; qr_svg?: string; otpauth_url?: string };

type Props = {
    factors: MfaListedFactor[];
    pending: Pending[];
    availableTypes: { type: MfaEnrollableType; label: string; recommended: boolean }[];
    recoveryCodesRemaining: number;
    recoveryCodes: string[] | null;
    retryAfter: number | null;
    /** Seconds until the password prompt may be tried again. */
    passwordRetryAfter: number | null;
    mustEnroll: boolean;
    /** What an enforced user must set up; [] = any method. */
    requiredTypes: { type: MfaEnrollableType; label: string }[];
    status: string | null;
    urls: { store: string; confirm: string; resend: string; destroy: string; recoveryCodes: string; confirmPassword: string };
};

const withId = (url: string, id: number) => url.replace('__ID__', String(id));

export default function MfaSettings(props: Props) {
    const { factors, pending, availableTypes, recoveryCodesRemaining, recoveryCodes, mustEnroll, requiredTypes, status, retryAfter, passwordRetryAfter, urls } = props;

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
    // your password first" (routes.password_confirmation): ask for it here,
    // then retry the change.
    const passwordForm = useForm<{ password?: string }>({});
    const [retry, setRetry] = useState<(() => void) | null>(null);
    const askPasswordFor = (action: () => void) => (errors: Record<string, string>) => {
        if (errors.password_confirmation_required) setRetry(() => action);
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
                retry?.();
            },
        });
    };

    const add = (type: MfaEnrollableType, destination?: string) => {
        store.transform(() => (destination === undefined ? { type } : { type, destination }));
        store.post(urls.store, { preserveScroll: true, onError: askPasswordFor(() => add(type, destination)) });
    };

    const confirmFactor = (id: number, code: string) => {
        setConfirmingId(id);
        confirmForm.transform(() => ({ code }));
        confirmForm.post(withId(urls.confirm, id), { preserveScroll: true });
    };

    const resendCode = (id: number) => {
        setResendingId(id);
        resend.transform(() => ({}));
        resend.post(withId(urls.resend, id), { preserveScroll: true });
    };

    const remove = (factor: MfaListedFactor) =>
        router.delete(withId(urls.destroy, factor.id), {
            preserveScroll: true,
            onStart: () => setRemovingId(factor.id),
            onFinish: () => setRemovingId(null),
            onError: askPasswordFor(() => remove(factor)),
        });

    const regenerate = (): void =>
        router.post(urls.recoveryCodes, {}, {
            preserveScroll: true,
            onStart: () => setRegenerating(true),
            onFinish: () => setRegenerating(false),
            onError: askPasswordFor(regenerate),
        });

    return (
        <div className="mx-auto max-w-2xl space-y-8 px-4 py-10">
            <Head title="Two-factor authentication" />

            <header className="space-y-1">
                <h1 className="text-xl font-semibold text-gray-900 dark:text-gray-100">Two-factor authentication</h1>
                <p className="text-sm text-gray-500 dark:text-gray-400">Add a second step to sign in, so a stolen password isn't enough.</p>
            </header>

            {retry && (
                <MfaPasswordConfirmForm
                    onConfirm={confirmPassword}
                    onCancel={() => setRetry(null)}
                    processing={passwordForm.processing}
                    error={passwordForm.errors.password}
                    retryAfter={passwordRetryAfter}
                />
            )}

            <MfaFactorList
                factors={factors}
                onRemove={remove}
                removingId={removingId}
                required={mustEnroll}
                requiredLabels={requiredTypes.map((t) => t.label)}
            />

            {pending.map((p) =>
                p.type === 'totp' ? (
                    <MfaTotpSetup
                        key={p.id}
                        label={p.type_label}
                        secret={p.secret ?? ''}
                        qrSvg={p.qr_svg}
                        onConfirm={(code) => confirmFactor(p.id, code)}
                        processing={confirmForm.processing && confirmingId === p.id}
                        error={confirmingId === p.id ? confirmForm.errors.code : null}
                    />
                ) : (
                    <MfaDestinationSetup
                        key={p.id}
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
                ),
            )}

            <MfaAddFactorForm types={availableTypes} onAdd={add} processing={store.processing} error={store.errors.destination ?? store.errors.type} />

            {(factors.length > 0 || recoveryCodes) && (
                <MfaRecoveryCodesPanel remaining={recoveryCodesRemaining} codes={recoveryCodes} onRegenerate={regenerate} processing={regenerating} />
            )}
        </div>
    );
}
