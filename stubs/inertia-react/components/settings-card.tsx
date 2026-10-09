// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The "Two-factor authentication" entry for an account settings page: says
// whether it's on and links to the MFA settings page. Standalone: needs only
// React, imports no other MFA file, and knows nothing about Inertia or routes.
//
// In an Inertia app, take the props from the shared MFA context, and pass
// Inertia's <Link> so the visit stays client-side:
//
//     import MfaSettingsCard from '@/components/vendor/laravel-mfa/settings-card';
//     import { mfaSettingsCardProps, useMfa } from '@/pages/mfa/mfa-context';
//     import { Link } from '@inertiajs/react';
//
//     <MfaSettingsCard {...mfaSettingsCardProps(useMfa())} renderLink={(link) => <Link {...link} />} />
import type { ReactNode } from 'react';

export type MfaSettingsCardLink = { href: string; className: string; children: ReactNode };

export type MfaSettingsCardProps = {
    /** MFA is switched on (MFA_ENABLED); renders nothing when false. */
    enabled?: boolean;
    /** The MFA settings page; renders nothing when null (MFA routes off). */
    settingsUrl: string | null;
    /** The user has at least one verification method. */
    hasMfa?: boolean;
    /** An enforcement rule requires this user to set one up. */
    mustEnroll?: boolean;
    className?: string;
    /** Renders the link, e.g. (link) => <Link {...link} />. Defaults to a plain <a>. */
    renderLink?: (link: MfaSettingsCardLink) => ReactNode;
};

const defaultLink = ({ href, className, children }: MfaSettingsCardLink) => (
    <a href={href} className={className}>
        {children}
    </a>
);

export default function MfaSettingsCard({ enabled = true, settingsUrl, hasMfa = false, mustEnroll = false, className = '', renderLink = defaultLink }: MfaSettingsCardProps) {
    if (!enabled || !settingsUrl) return null;

    const badge = hasMfa
        ? { text: 'On', className: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' }
        : mustEnroll
          ? { text: 'Required', className: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-300' }
          : { text: 'Off', className: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' };

    return (
        <section
            aria-labelledby="mfa-settings-card"
            className={`flex flex-wrap items-center justify-between gap-4 rounded-lg border border-gray-200 p-4 dark:border-gray-800 ${className}`}
        >
            <div className="min-w-0 space-y-1">
                <div className="flex items-center gap-2">
                    <h2 id="mfa-settings-card" className="text-sm font-medium text-gray-900 dark:text-gray-100">
                        Two-factor authentication
                    </h2>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${badge.className}`}>{badge.text}</span>
                </div>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {hasMfa
                        ? "Signing in asks for a second step, so a stolen password isn't enough."
                        : mustEnroll
                          ? 'Your account requires a second sign-in step. Set it up to continue.'
                          : "Add a second step to sign in, so a stolen password isn't enough."}
                </p>
            </div>
            {renderLink({
                href: settingsUrl,
                className: 'shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-900 dark:border-gray-700 dark:text-gray-100',
                children: hasMfa ? 'Manage' : 'Set up',
            })}
        </section>
    );
}
