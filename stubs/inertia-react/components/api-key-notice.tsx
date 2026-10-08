// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// Show next to API key management: API keys authenticate without MFA.
//
//     import MfaApiKeyNotice from '@/components/mfa/api-key-notice';
//     <MfaApiKeyNotice />
import { useMfa } from './mfa-context';

export default function MfaApiKeyNotice({ className = '' }: { className?: string }) {
    const mfa = useMfa();

    if (mfa && !mfa.enabled) return null;

    const settingsUrl = mfa?.user && !mfa.user.hasMfa ? mfa.urls.settings : null;

    return (
        <div role="note" className={`rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 ${className}`}>
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
