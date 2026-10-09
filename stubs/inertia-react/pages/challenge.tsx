// Published by strontiumcorp/laravel-mfa. This file is yours — wrap it in your
// app's guest layout. It only wires Inertia to the components in
// components/vendor/laravel-mfa/, which hold the UI.
import MfaChallengeForm, { type MfaChallengeFactor } from '@/components/vendor/laravel-mfa/challenge-form';
import MfaRecoveryCodeForm from '@/components/vendor/laravel-mfa/recovery-code-form';
import MfaSendCodeButton from '@/components/vendor/laravel-mfa/send-code-button';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    factors: MfaChallengeFactor[];
    defaultFactorId: number | null;
    hasRecoveryCodes: boolean;
    status: string | null;
    retryAfter: number | null;
    urls: { send: string; verify: string; recover: string; logout: string | null };
};

export default function MfaChallenge({ factors, defaultFactorId, hasRecoveryCodes, status, retryAfter, urls }: Props) {
    const [factorId, setFactorId] = useState<number | null>(defaultFactorId);
    const [useRecovery, setUseRecovery] = useState(false);
    // status and retryAfter describe the last send; show them only on that factor.
    const [sentToId, setSentToId] = useState<number | null>(null);
    const factor = factors.find((f) => f.id === factorId) ?? null;
    const logoutUrl = urls.logout;

    // Each endpoint reports failures under "code" (send: cooldown, delivery).
    // The components hold the input; transform() adds it when posting.
    const verify = useForm<{ code?: string }>({});
    const send = useForm<{ code?: string }>({});
    const recover = useForm<{ code?: string }>({});

    const selectFactor = (id: number) => {
        setFactorId(id);
        verify.clearErrors();
        send.clearErrors();
    };

    const submitCode = (code: string) => {
        verify.transform(() => ({ factor_id: factorId, code }));
        verify.post(urls.verify, { preserveScroll: true });
    };

    const sendCode = () => {
        setSentToId(factorId);
        send.transform(() => ({ factor_id: factorId }));
        send.post(urls.send, { preserveScroll: true });
    };

    const submitRecovery = (code: string) => {
        recover.transform(() => ({ code }));
        recover.post(urls.recover, { preserveScroll: true });
    };

    const signOut = logoutUrl ? () => router.post(logoutUrl) : undefined;

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-950">
            <Head title="Verify it's you" />

            <div className="w-full max-w-sm space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h1 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Verify it's you</h1>

                {useRecovery ? (
                    <MfaRecoveryCodeForm
                        onSubmit={submitRecovery}
                        processing={recover.processing}
                        error={recover.errors.code}
                        onUseVerificationCode={() => setUseRecovery(false)}
                        onSignOut={signOut}
                    />
                ) : (
                    <MfaChallengeForm
                        factors={factors}
                        selectedFactorId={factorId}
                        onSelectFactor={selectFactor}
                        onSubmit={submitCode}
                        processing={verify.processing}
                        error={verify.errors.code}
                        onUseRecoveryCode={hasRecoveryCodes ? () => setUseRecovery(true) : undefined}
                        onSignOut={signOut}
                    >
                        {(factor?.type === 'email' || factor?.type === 'sms') && (
                            <MfaSendCodeButton
                                onSend={sendCode}
                                processing={send.processing}
                                retryAfter={sentToId === factorId ? retryAfter : null}
                                sent={sentToId === factorId && status === 'code-sent'}
                                error={send.errors.code}
                            />
                        )}
                    </MfaChallengeForm>
                )}
            </div>
        </div>
    );
}
