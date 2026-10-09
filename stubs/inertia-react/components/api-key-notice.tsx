// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Show next to API key management: API keys authenticate without MFA.
// Standalone: needs only React, imports no other MFA file, and knows nothing
// about Inertia or routes.
//
// In an Inertia app, take the props from the shared MFA context:
//
//     import MfaApiKeyNotice from '@/components/vendor/laravel-mfa/api-key-notice';
//     import { mfaApiKeyNoticeProps, useMfa } from '@/pages/mfa/mfa-context';
//
//     <MfaApiKeyNotice {...mfaApiKeyNoticeProps(useMfa())} />

export type MfaApiKeyNoticeProps = {
    /** MFA is switched on (MFA_ENABLED); renders nothing when false. */
    enabled?: boolean;
    /** Adds a "Turn on two-factor authentication" link, for users without MFA. */
    settingsUrl?: string | null;
    className?: string;
};

export default function MfaApiKeyNotice({ enabled = true, settingsUrl = null, className = '' }: MfaApiKeyNoticeProps) {
    if (!enabled) return null;

    return (
        <div role="note" className={`rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200 ${className}`}>
            <p className="font-medium">Two-factor authentication isn't enforced for API keys.</p>
            <p className="mt-1">
                Requests made with an API key skip the two-factor check. If you think your API key or your password has
                leaked, regenerate your API key right away.
                {settingsUrl && (
                    <>
                        {' '}
                        <a href={settingsUrl} className="underline">
                            Turn on two-factor authentication
                        </a>{' '}
                        to protect your account sign-in.
                    </>
                )}
            </p>
        </div>
    );
}
