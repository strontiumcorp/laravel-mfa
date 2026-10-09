// Published by strontiumcorp/laravel-mfa. This file is yours — wrap it in your
// app's guest layout. It only wires Inertia to the components in
// components/vendor/laravel-mfa/, which hold the UI.
import MfaChallengeForm, { type MfaChallengeFactor } from '@/components/vendor/laravel-mfa/challenge-form';
import MfaRecoveryCodeForm from '@/components/vendor/laravel-mfa/recovery-code-form';
import MfaSendCodeButton from '@/components/vendor/laravel-mfa/send-code-button';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useReducer, useRef, useState } from 'react';

type Props = {
    factors: MfaChallengeFactor[];
    defaultFactorId: number | null;
    hasRecoveryCodes: boolean;
    status: string | null;
    retryAfter: number | null;
    /** "Don't ask again on this browser" is offered for this many days; null = not offered. */
    trustBrowser: { days: number } | null;
    /**
     * Verifying early (?renew=1, from the trusted browser reminder): the user is
     * already verified and comes back to keep this browser trusted. The box
     * starts ticked and "Not now" goes back instead of "Sign out".
     */
    renew: boolean;
    urls: { send: string; verify: string; recover: string; logout: string | null };
};

export default function MfaChallenge({ factors, defaultFactorId, hasRecoveryCodes, status, retryAfter, trustBrowser, renew = false, urls }: Props) {
    const [factorId, setFactorId] = useState<number | null>(defaultFactorId);
    const [useRecovery, setUseRecovery] = useState(false);
    // Leaving the recovery form with "Try another way" reopens the list of methods.
    const [backToList, setBackToList] = useState(false);
    // status and retryAfter describe the last send; show them only on that factor.
    const [sentToId, setSentToId] = useState<number | null>(null);
    const factor = factors.find((f) => f.id === factorId) ?? null;
    const delivered = factor?.type === 'email' || factor?.type === 'sms';
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

    // remember: skip the challenge on this browser for trustBrowser.days (only sent when ticked).
    const submitCode = (code: string, options?: { trustBrowser: boolean }) => {
        const remember = Boolean(trustBrowser && options?.trustBrowser);
        verify.transform(() => (remember ? { factor_id: factorId, code, remember: true } : { factor_id: factorId, code }));
        verify.post(urls.verify, { preserveScroll: true });
    };

    // Methods a code was asked for in this visit (by hand or by the page).
    const requested = useRef(new Set<number>());
    const sendCode = () => {
        if (factorId !== null) requested.current.add(factorId);
        setSentToId(factorId);
        send.transform(() => ({ factor_id: factorId }));
        send.post(urls.send, { preserveScroll: true });
    };

    const submitRecovery = (code: string) => {
        recover.transform(() => ({ code }));
        recover.post(urls.recover, { preserveScroll: true });
    };

    // retry_after and expires_in are relative to the response, so pin them to
    // the clock when props arrive: a method shown a minute later still counts
    // from then. The factor's own state comes first (the server's view after
    // any send); the last send's flashed retryAfter fills in for its factor.
    const deadlines = useMemo(() => {
        const at = (seconds: number | null | undefined) => (seconds ? Date.now() + seconds * 1000 : null);

        return new Map(
            factors.map((f) => [f.id, { resend: at(f.retry_after) ?? (f.id === sentToId ? at(retryAfter) : null), expires: at(f.expires_in) }]),
        );
    }, [factors, retryAfter, status]);
    const resendIn = (id: number) => Math.max(0, Math.ceil(((deadlines.get(id)?.resend ?? 0) - Date.now()) / 1000));

    // Render again when the code on screen expires, and when its wait ends.
    const expiresAt = (factor && deadlines.get(factor.id)?.expires) ?? null;
    const resendAt = (factor && deadlines.get(factor.id)?.resend) ?? null;
    const [, tick] = useReducer((n: number) => n + 1, 0);
    useEffect(() => {
        const timers = [expiresAt, resendAt]
            .filter((at): at is number => at !== null && at > Date.now())
            .map((at) => setTimeout(tick, at - Date.now()));
        return () => timers.forEach(clearTimeout);
    }, [expiresAt, resendAt]);
    const expired = expiresAt !== null && expiresAt <= Date.now();

    // Verifying early, the user is signed in and verified already: offer the way back, not out.
    const signOut = logoutUrl && !renew ? () => router.post(logoutUrl) : undefined;
    // Back where they came from; opened in a new tab (no history), to the app's start page.
    const goBack = renew ? () => (window.history.length > 1 ? window.history.back() : window.location.assign('/')) : undefined;
    const sentHere = sentToId === factorId;
    // A code is out: from the server (it survives a refresh), or the send just
    // answered; until it expires, when the server says when that is.
    const codeOut = !expired && (Boolean(factor?.code_sent) || (sentHere && status === 'code-sent'));
    // No code is out, but the next one has to wait: one was just used (the
    // server's cooldown spans logins), or the last send was refused.
    const waiting = delivered && !codeOut && resendAt !== null && resendAt > Date.now();
    // A send refused while the server's own state says its cooldown runs is
    // the cooldown (it is checked before any other limit), e.g. an auto-send
    // after back/forward restored stale props. Not an error: the countdown
    // says when another can be sent.
    const sendError = sentHere && !factor?.retry_after ? send.errors.code : undefined;

    // Send a code when an email/SMS method is shown without one (on arrival,
    // when picked, when the one out expires, or when the wait after a used
    // code ends), once per method per visit. Never from the GET itself, so
    // prefetches and back/forward don't send.
    useEffect(() => {
        if (delivered && !codeOut && !waiting && !requested.current.has(factor.id)) sendCode();
    }, [factorId, codeOut, waiting]);

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10 dark:bg-gray-950">
            <Head title="Verify it's you" />

            <main className="w-full max-w-sm">
                <h1 className="sr-only">Verify it's you</h1>
                {renew && trustBrowser && !useRecovery && (
                    <p className="mb-4 text-center text-sm text-gray-600 dark:text-gray-400">
                        Verify now so this browser keeps skipping the code for another {trustBrowser.days} {trustBrowser.days === 1 ? 'day' : 'days'}.
                    </p>
                )}

                {useRecovery ? (
                    <MfaRecoveryCodeForm
                        onSubmit={submitRecovery}
                        processing={recover.processing}
                        error={recover.errors.code}
                        onTryAnotherWay={() => {
                            setUseRecovery(false);
                            setBackToList(true);
                        }}
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
                        initialView={backToList ? 'methods' : 'code'}
                        sent={codeOut}
                        sendFailed={Boolean(sendError)}
                        expired={expired && !send.processing}
                        waiting={waiting}
                        trustBrowserDays={trustBrowser?.days ?? null}
                        trustBrowserDefault={renew}
                        onCancel={goBack}
                    >
                        {delivered && (
                            <MfaSendCodeButton
                                onSend={sendCode}
                                processing={send.processing}
                                retryAfter={resendIn(factor.id) || null}
                                sent={codeOut}
                                error={sendError}
                            />
                        )}
                    </MfaChallengeForm>
                )}
            </main>
        </div>
    );
}
