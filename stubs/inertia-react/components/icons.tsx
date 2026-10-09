// Published by strontiumcorp/laravel-mfa. This file is yours — swap freely.
//
// Every icon the MFA components draw, in one place. The components import
// these names from './icons' and nothing else, so changing icon set means
// editing only this file: keep the exported names and props, e.g.
//
//     import { Smartphone } from 'lucide-react';
//     export const MfaIconAuthenticator = (props: MfaIconProps) => <Smartphone size={props.size ?? 24} className={props.className} aria-hidden />;
//
// Needs only React. A component copied into another project needs this file too.
import type { ReactNode, SVGProps } from 'react';

export type MfaIconProps = {
    /** Width and height in px; defaults to 24. */
    size?: number;
    className?: string;
    /** Names the icon for screen readers; without it the icon is decorative (aria-hidden). */
    title?: string;
};

export type MfaFactorType = 'totp' | 'email' | 'sms';

/** Outline icons on a 24×24 grid, drawn in currentColor. */
function Svg({ size = 24, className, title, strokeWidth = 1.75, children }: MfaIconProps & { strokeWidth?: number; children: ReactNode }) {
    const a11y: SVGProps<SVGSVGElement> = title ? { role: 'img', 'aria-label': title } : { 'aria-hidden': true };

    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            {...a11y}
        >
            {children}
        </svg>
    );
}

/** An authenticator app: a phone with a lock. */
export function MfaIconAuthenticator(props: MfaIconProps) {
    return (
        <Svg {...props}>
            <rect x="5" y="2" width="14" height="20" rx="3" />
            <path d="M12 18h.01" />
            <path d="M9.5 10V8.75a2.5 2.5 0 0 1 5 0V10" />
            <rect x="8.5" y="10" width="7" height="4.75" rx="1" />
        </Svg>
    );
}

export function MfaIconEmail(props: MfaIconProps) {
    return (
        <Svg {...props}>
            <rect x="3" y="5" width="18" height="14" rx="2.5" />
            <path d="m4 7.5 8 5.5 8-5.5" />
        </Svg>
    );
}

/** A text message: a chat bubble. */
export function MfaIconSms(props: MfaIconProps) {
    return (
        <Svg {...props}>
            <path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.2A8 8 0 1 1 20 12Z" />
            <path d="M8.5 12h.01" />
            <path d="M12 12h.01" />
            <path d="M15.5 12h.01" />
        </Svg>
    );
}

/** Recovery codes: a key. */
export function MfaIconKey(props: MfaIconProps) {
    return (
        <Svg {...props}>
            <circle cx="8" cy="15" r="4" />
            <path d="m10.85 12.15 9.15-9.15" />
            <path d="m16.5 6.5 2.5 2.5" />
            <path d="m14 9 2 2" />
        </Svg>
    );
}

/** An account requirement: a shield with an exclamation mark. */
export function MfaIconShieldAlert(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <path d="M12 3 5 6v5.5c0 4.3 3 8 7 9.5 4-1.5 7-5.2 7-9.5V6l-7-3Z" />
            <path d="M12 9v3" />
            <path d="M12 15.5h.01" />
        </Svg>
    );
}

export function MfaIconShieldCheck(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <path d="M12 3 5 6v5.5c0 4.3 3 8 7 9.5 4-1.5 7-5.2 7-9.5V6l-7-3Z" />
            <path d="m9 12 2 2 4-4" />
        </Svg>
    );
}

/** Turning two-factor on: a shield with a padlock. */
export function MfaIconShieldLock(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <path d="M12 3 5 6v5.5c0 4.3 3 8 7 9.5 4-1.5 7-5.2 7-9.5V6l-7-3Z" />
            <rect x="9" y="11" width="6" height="4.5" rx="1" />
            <path d="M10.5 11V9.75a1.5 1.5 0 0 1 3 0V11" />
        </Svg>
    );
}

/** A trusted browser: a browser window. */
export function MfaIconBrowser(props: MfaIconProps) {
    return (
        <Svg {...props}>
            <rect x="3" y="4" width="18" height="16" rx="2.5" />
            <path d="M3 9h18" />
            <path d="M6.5 6.5h.01" />
            <path d="M9 6.5h.01" />
        </Svg>
    );
}

export function MfaIconCheck(props: MfaIconProps) {
    return (
        <Svg strokeWidth={3} {...props}>
            <path d="m5 12 5 5 9-10" />
        </Svg>
    );
}

export function MfaIconClose(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <path d="M18 6 6 18" />
            <path d="m6 6 12 12" />
        </Svg>
    );
}

export function MfaIconCopy(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <rect x="9" y="9" width="12" height="12" rx="2" />
            <path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1" />
        </Svg>
    );
}

export function MfaIconDownload(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <path d="M12 3v12" />
            <path d="m7 10 5 5 5-5" />
            <path d="M5 21h14" />
        </Svg>
    );
}

export function MfaIconChevronRight(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <path d="m9 6 6 6-6 6" />
        </Svg>
    );
}

export function MfaIconChevronLeft(props: MfaIconProps) {
    return (
        <Svg strokeWidth={2} {...props}>
            <path d="m15 6-6 6 6 6" />
        </Svg>
    );
}

/** The icon for a factor type. */
export function MfaFactorIcon({ type, ...props }: MfaIconProps & { type: MfaFactorType }) {
    if (type === 'totp') return <MfaIconAuthenticator {...props} />;
    if (type === 'email') return <MfaIconEmail {...props} />;

    return <MfaIconSms {...props} />;
}
