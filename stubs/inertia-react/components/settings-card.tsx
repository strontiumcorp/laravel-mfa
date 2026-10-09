// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The "Two-factor authentication" section for an account settings page: says
// whether it's on and links to the MFA settings page. It draws its own card
// (light and dark), so it needs no wrapper; pass className to replace the
// card's surface (background, border, radius, padding, shadow) with your
// page's, e.g. className="rounded-2xl bg-white p-4 shadow sm:p-8 dark:bg-[#1E1F24]".
// Standalone: needs only React and ./icons, and knows nothing about Inertia or routes.
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
import { MfaIconShieldCheck } from './icons';

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
    /** Replaces the card's surface classes (background, border, radius, padding, shadow); the content keeps its own styles. */
    className?: string;
    /** Renders the link, e.g. (link) => <Link {...link} />. Defaults to a plain <a>. */
    renderLink?: (link: MfaSettingsCardLink) => ReactNode;
};

// The card's own surface; className replaces it.
const SURFACE = 'rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-8 dark:border-gray-800 dark:bg-gray-900';
const ACTION =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 dark:focus-visible:ring-gray-100 dark:focus-visible:ring-offset-gray-900';
const PRIMARY = `${ACTION} bg-gray-900 text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white`;
const SECONDARY = `${ACTION} border border-gray-300 bg-white text-gray-900 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800`;

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
        <section aria-labelledby="mfa-settings-card" className={className || SURFACE}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 id="mfa-settings-card" className="text-lg font-semibold text-gray-900 sm:text-xl dark:text-gray-100">
                    Two-factor authentication
                </h2>
                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${badge.className}`}>{badge.text}</span>
            </div>
            <p className="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-400">
                {hasMfa
                    ? "Signing in asks for a second step, so a stolen password isn't enough."
                    : mustEnroll
                      ? 'Your account requires a second sign-in step. Set it up to continue.'
                      : "Add a second step to sign in, so a stolen password isn't enough."}
            </p>
            <div className="mt-5 sm:mt-6">
                {renderLink({
                    href: settingsUrl,
                    className: hasMfa ? SECONDARY : PRIMARY,
                    children: hasMfa ? (
                        <>
                            <MfaIconShieldCheck size={16} className="size-4" />
                            Manage
                        </>
                    ) : (
                        'Set up'
                    ),
                })}
            </div>
        </section>
    );
}
