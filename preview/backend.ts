// A fake MFA server for the preview: the same endpoints and response rules as
// the package (see docs/json-mode.md), held in memory per frame. Code 123456
// passes every check, the password is "password", and recovery code
// aaaaa-11111 always works. Nothing here is published.

export type FactorType = 'totp' | 'email' | 'sms';

export type Factor = {
    id: number;
    type: FactorType;
    type_label: string;
    label: string | null;
    destination: string | null;
    confirmed: boolean;
    confirmed_at: string | null;
    last_used_at: string | null;
    secret?: string;
    qr_svg?: string;
    otpauth_url?: string;
};

export type TrustedBrowser = {
    id: number;
    label: string | null;
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string;
    current: boolean;
};

export type State = {
    factors: Factor[];
    pending: Factor[];
    recoveryCodesRemaining: number;
    mustEnroll: boolean;
    /** An enforced user's required types (enforcement.required_types): only these can be added, and the challenge asks for each one held. */
    requiredTypes: FactorType[];
    /** Challenge: the required types already passed in this challenge. */
    stepsPassed: FactorType[];
    enabledTypes: FactorType[];
    /** Factor changes need the password (routes.password_confirmation). */
    requirePassword: boolean;
    passwordConfirmed: boolean;
    passwordAttempts: number;
    /**
     * Adding the first method needs a code emailed to the account first
     * (enrollment verification): the masked address, or null when only an
     * administrator's setup link can do it. undefined = not needed.
     */
    enrollmentEmail?: string | null;
    /** The account check passed (or wasn't needed). */
    enrollmentVerified: boolean;
    /** When the account-check code went out (ms), for its 60s cooldown. */
    enrollmentCodeSentAt: number | null;
    /** "Not today" on the nudge was clicked (until the frame reloads). */
    nudgeDismissed: boolean;
    /** Challenge: when each email/SMS factor's code went out (ms), for its send state. */
    codeSentAt: Record<number, number>;
    /** Challenge: when a code was last used to verify (ms): the next send waits 120s from then (the cooldown spans logins). */
    codeUsedAt: Record<number, number>;
    /** Trusted browsers (mfa.trusted_browsers): days a ticked "don't ask again" lasts; null = the feature is off. */
    trustBrowserDays: number | null;
    /** Newest first. */
    trustedBrowsers: TrustedBrowser[];
    /** This browser's trust ends in this many minutes (the app pages show the reminder); null = not a trusted browser. */
    trustEndsInMinutes: number | null;
    /** The verification's fixed window (mfa.lifetime) ends at this time (ms); null = no window. */
    lifetimeEndsAt: number | null;
    /** The idle timeout ends at this time (ms); null = no idle timeout. */
    idleExpiresAt: number | null;
    /** "Later" on the "check coming up" reminder (until the next verification). */
    reminderDismissed: boolean;
    /** The challenge opened from the reminder's "Verify now" (?renew=1). */
    renew: boolean;
    // One-request flashes, as the package's session flashes.
    status: string | null;
    retryAfter: number | null;
    passwordRetryAfter: number | null;
    recoveryCodes: string[] | null;
};

export type Result = { errors?: Record<string, string>; toast?: string };

export const CODE = '123456';
export const PASSWORD = 'password';
export const RECOVERY_CODE = 'aaaaa-11111';

export const LABELS: Record<FactorType, string> = { totp: 'Authenticator app', email: 'Email', sms: 'SMS' };

export const urls = {
    settings: {
        store: '/mfa/factors',
        confirm: '/mfa/factors/__ID__/confirm',
        resend: '/mfa/factors/__ID__/resend',
        destroy: '/mfa/factors/__ID__',
        recoveryCodes: '/mfa/recovery-codes',
        confirmPassword: '/mfa/confirm-password',
        sendEnrollmentCode: '/mfa/enrollment-verification/send',
        verifyEnrollmentCode: '/mfa/enrollment-verification',
        forgetTrustedBrowser: '/mfa/trusted-browsers/__ID__',
        forgetTrustedBrowsers: '/mfa/trusted-browsers',
    },
    challenge: { send: '/mfa/challenge/send', verify: '/mfa/challenge', recover: '/mfa/challenge/recover', logout: '/logout' },
    nudgeDismiss: '/mfa/nudge/dismiss',
    reminderDismiss: '/mfa/reminder/dismiss',
    reminderVerify: '/mfa/challenge?renew=1',
    keepAlive: '/mfa/session/keep-alive',
    sessionState: '/mfa/session',
};

/** The enforced profile's idle timeout in the preview (mfa.lifetime.profiles.enforced.idle, 25 minutes). */
export const IDLE_SECONDS = 25 * 60;

export const LIFETIME_REMINDER = {
    title: 'Two-factor check coming up',
    body: "For your security you'll be asked for your sign-in code again :when. Do it now so it doesn't interrupt you.",
    button: 'Verify now',
    dismissLabel: 'Later',
};

export const TRUST_REMINDER = {
    title: 'Two-factor check coming up',
    body: "This browser will ask for your sign-in code again :when. Do it now so it doesn't interrupt you later.",
    button: 'Verify now',
    dismissLabel: 'Later',
};

export const NUDGE = {
    title: 'Protect your account',
    body: 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
    button: 'Turn on',
    dismissLabel: 'Not today',
};

let nextId = 100;

export function factor(type: FactorType, overrides: Partial<Factor> = {}): Factor {
    return {
        id: nextId++,
        type,
        type_label: LABELS[type],
        label: null,
        destination: type === 'email' ? 'j***@example.com' : type === 'sms' ? '+*******0100' : null,
        confirmed: true,
        confirmed_at: '2026-10-02T10:00:00Z',
        last_used_at: null,
        ...overrides,
    };
}

export function pendingTotp(): Factor {
    const secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    return factor('totp', {
        confirmed: false,
        confirmed_at: null,
        secret,
        qr_svg: sampleQr(secret),
        otpauth_url: `otpauth://totp/Preview:jane%40example.com?secret=${secret}&issuer=Preview`,
    });
}

const DAY = 86_400_000;

/** A trusted browser added `addedDaysAgo` days ago, trusted for `days`. */
export function trustedBrowser(label: string | null, addedDaysAgo: number, overrides: Partial<TrustedBrowser> = {}, days = 30): TrustedBrowser {
    const added = Date.now() - addedDaysAgo * DAY;

    return {
        id: nextId++,
        label,
        created_at: new Date(added).toISOString(),
        last_used_at: null,
        expires_at: new Date(added + days * DAY).toISOString(),
        current: false,
        ...overrides,
    };
}

export function initialState(overrides: Partial<State> = {}): State {
    return {
        factors: [],
        pending: [],
        recoveryCodesRemaining: 0,
        mustEnroll: false,
        requiredTypes: [],
        stepsPassed: [],
        enabledTypes: ['totp', 'email', 'sms'],
        requirePassword: false,
        passwordConfirmed: false,
        passwordAttempts: 0,
        enrollmentVerified: false,
        enrollmentCodeSentAt: null,
        codeSentAt: {},
        codeUsedAt: {},
        trustBrowserDays: null,
        trustedBrowsers: [],
        trustEndsInMinutes: null,
        lifetimeEndsAt: null,
        idleExpiresAt: null,
        reminderDismissed: false,
        renew: false,
        nudgeDismissed: false,
        status: null,
        retryAfter: null,
        passwordRetryAfter: null,
        recoveryCodes: null,
        ...overrides,
    };
}

const newCodes = () => Array.from({ length: 10 }, (_, i) => `${'abcdefghij'[i].repeat(5)}-${String(i + 1).padStart(5, '1')}`);

const required = (s: State, type: FactorType) => s.requiredTypes.length === 0 || s.requiredTypes.includes(type);

/** Handle one request, like the package's controllers, mutating the state. */
export function handle(s: State, method: 'post' | 'delete', url: string, data: Record<string, unknown>): Result {
    // Flashes last one request.
    s.status = s.retryAfter = s.passwordRetryAfter = null;
    s.recoveryCodes = null;

    const id = Number(url.match(/\/factors\/(\d+)/)?.[1]);
    const needsPassword = () => s.requirePassword && !s.passwordConfirmed;
    const passwordRequired = { errors: { password_confirmation_required: 'Please confirm your password to continue.' } };
    const verificationRequired = { errors: { enrollment_verification_required: "Confirm it's you before adding your first sign-in method." } };

    if (method === 'post' && url === urls.settings.confirmPassword) {
        if (++s.passwordAttempts > 5) {
            s.passwordRetryAfter = 60;
            return { errors: { password: 'Too many attempts. Please try again later.' } };
        }
        if (data.password !== PASSWORD) return { errors: { password: 'The provided password is incorrect.' } };
        s.passwordAttempts = 0;
        s.passwordConfirmed = true;
        s.status = 'password-confirmed';
        return {};
    }

    if (method === 'post' && url === urls.settings.sendEnrollmentCode) {
        if (needsPassword()) return passwordRequired;
        if (s.enrollmentEmail === null) return { errors: { code: 'Ask an administrator for a setup link to add your first sign-in method.' } };
        const wait = s.enrollmentCodeSentAt === null ? 0 : 60 - Math.floor((Date.now() - s.enrollmentCodeSentAt) / 1000);
        if (wait > 0) {
            s.retryAfter = wait;
            return { errors: { code: 'Please wait before requesting another code.' } };
        }
        s.enrollmentCodeSentAt = Date.now();
        s.retryAfter = 60;
        s.status = 'enrollment-code-sent';
        return { toast: `Sent code ${CODE} (preview)` };
    }

    if (method === 'post' && url === urls.settings.verifyEnrollmentCode) {
        if (data.code !== CODE) return { errors: { code: 'The provided code is invalid.' } };
        s.enrollmentVerified = true;
        s.status = 'enrollment-verified';
        return {};
    }

    if (method === 'post' && url === urls.settings.store) {
        if (needsPassword()) return passwordRequired;
        if (needsVerification(s)) return verificationRequired;
        const type = data.type as FactorType;
        let pending: Factor;

        if (type === 'totp') {
            pending = pendingTotp();
        } else {
            const destination = String(data.destination ?? '');
            if (type === 'sms' && !/^\+\d{8,15}$/.test(destination.replace(/[\s()-]/g, ''))) {
                return { errors: { destination: "We can't send verification codes to this destination." } };
            }
            const masked = type === 'sms' ? `+${'*'.repeat(7)}${destination.slice(-4)}` : destination ? `${destination[0]}***@${destination.split('@')[1] ?? 'example.com'}` : 'j***@example.com';
            pending = factor(type, { confirmed: false, confirmed_at: null, destination: masked });
            s.retryAfter = 120;
        }

        s.pending = [...s.pending.filter((p) => p.type !== type), pending];
        s.status = 'enrollment-started';
        return type === 'totp' ? {} : { toast: `Sent code ${CODE} (preview)` };
    }

    if (method === 'post' && url.endsWith('/confirm')) {
        if (needsVerification(s)) return verificationRequired;
        const pending = s.pending.find((p) => p.id === id);
        if (!pending) return { errors: { code: 'This verification method is not available.' } };
        if (data.code !== CODE) return { errors: { code: 'The provided code is invalid.' } };

        const first = s.factors.length === 0;
        s.pending = s.pending.filter((p) => p.id !== id);
        s.factors = [...s.factors, { ...pending, confirmed: true, confirmed_at: new Date().toISOString(), secret: undefined, qr_svg: undefined, otpauth_url: undefined }];
        if (first || s.recoveryCodesRemaining === 0) {
            s.recoveryCodes = newCodes();
            s.recoveryCodesRemaining = 10;
        }
        if (s.mustEnroll && required(s, pending.type)) s.mustEnroll = false;
        s.status = 'factor-enabled';
        return {};
    }

    if (method === 'post' && url.endsWith('/resend')) {
        s.retryAfter = 120;
        s.status = 'code-sent';
        return { toast: `Sent code ${CODE} (preview)` };
    }

    if (method === 'delete' && url.startsWith('/mfa/factors/')) {
        if (needsPassword()) return passwordRequired;
        s.factors = s.factors.filter((f) => f.id !== id);
        if (s.factors.length === 0) s.recoveryCodesRemaining = 0;
        s.status = 'factor-disabled';
        return {};
    }

    if (method === 'post' && url === urls.settings.recoveryCodes) {
        if (needsPassword()) return passwordRequired;
        s.recoveryCodes = newCodes();
        s.recoveryCodesRemaining = 10;
        s.status = 'recovery-codes-generated';
        return {};
    }

    if (method === 'post' && url === urls.challenge.send) {
        const f = s.factors.find((f) => f.id === data.factor_id);
        const used = f ? usedWait(s, f) : null;
        if (used) {
            s.retryAfter = used;
            return { errors: { code: 'Please wait before requesting another code.' } };
        }
        s.status = 'code-sent';
        if (f?.type === 'totp') return {};
        if (f) s.codeSentAt[f.id] = Date.now();
        s.retryAfter = 120;
        return { toast: `Sent code ${CODE} (preview)` };
    }

    if (method === 'post' && url === urls.challenge.verify) {
        if (data.code !== CODE) return { errors: { code: 'The provided code is invalid.' } };
        const passedType = s.factors.find((f) => f.id === data.factor_id)?.type;
        const steps = challengeSteps(s);
        if (steps && passedType && !s.stepsPassed.includes(passedType)) {
            s.stepsPassed = [...s.stepsPassed, passedType];
            if (s.stepsPassed.length < steps.total) {
                s.status = 'factor-verified';
                return { toast: `${LABELS[passedType]} accepted. One more to go.` };
            }
        }
        if (data.remember === true && s.trustBrowserDays !== null) {
            s.trustEndsInMinutes = s.trustBrowserDays * 24 * 60;
            s.trustedBrowsers = [trustedBrowser('Chrome on Mac', 0, { current: true }, s.trustBrowserDays), ...s.trustedBrowsers.map((b) => ({ ...b, current: false }))];
            return { toast: `Verified. This browser won't be asked again for ${s.trustBrowserDays} days.` };
        }
        return { toast: 'Verified. The app would now open the page the user asked for.' };
    }

    if (method === 'delete' && url.startsWith('/mfa/trusted-browsers')) {
        const browserId = Number(url.match(/\/trusted-browsers\/(\d+)/)?.[1]);
        s.trustedBrowsers = url === urls.settings.forgetTrustedBrowsers ? [] : s.trustedBrowsers.filter((b) => b.id !== browserId);
        s.status = 'trusted-browsers-forgotten';
        return {};
    }

    if (method === 'post' && url === urls.challenge.recover) {
        return String(data.code).trim().toLowerCase() === RECOVERY_CODE
            ? { toast: 'Verified with a recovery code. 9 left.' }
            : { errors: { code: 'The provided code is invalid.' } };
    }

    if (method === 'post' && url === urls.challenge.logout) return { toast: 'Signed out (preview).' };

    if (method === 'post' && url === urls.reminderDismiss) {
        s.reminderDismissed = true;
        s.status = 'reminder-dismissed';
        return {};
    }

    if (method === 'post' && url === urls.keepAlive) {
        if (s.idleExpiresAt === null || s.idleExpiresAt <= Date.now()) return { errors: { session: 'Multi-factor authentication required.' } };
        s.idleExpiresAt = Date.now() + IDLE_SECONDS * 1000;
        return {};
    }

    if (method === 'post' && url === urls.nudgeDismiss) {
        s.nudgeDismissed = true;
        return { toast: `Hidden until midnight in ${String(data.timezone ?? 'the app timezone')} (preview).` };
    }

    return { toast: `No preview handler for ${method.toUpperCase()} ${url}` };
}

/** The settings page's props, as SettingsController::show() builds them. */
export function settingsProps(s: State) {
    // An enforced user may add only the required types (Mfa::enrollableTypes()).
    const available = s.enabledTypes
        .filter((type) => s.requiredTypes.length === 0 || s.requiredTypes.includes(type))
        .map((type) => ({ type, label: LABELS[type], recommended: type === 'totp' }));

    return {
        factors: s.factors,
        pending: s.pending,
        availableTypes: [...available].sort((a, b) => Number(b.recommended) - Number(a.recommended)),
        recoveryCodesRemaining: s.recoveryCodesRemaining,
        recoveryCodesTotal: 10,
        recoveryCodesFile: { app: 'Acme (local)', slug: 'acme-local', account: 'jane@example.com' },
        mustEnroll: s.mustEnroll,
        requiredTypes: s.mustEnroll || s.requiredTypes.length > 0 ? s.requiredTypes.map((type) => ({ type, label: LABELS[type] })) : [],
        passwordRetryAfter: s.passwordRetryAfter,
        passwordConfirmationRequired: s.requirePassword && !s.passwordConfirmed,
        enrollmentVerification: needsVerification(s) ? { email: s.enrollmentEmail ?? null } : null,
        nudge: s.factors.length === 0 && !s.mustEnroll ? { title: NUDGE.title, body: NUDGE.body } : null,
        trustedBrowsers: s.trustBrowserDays === null ? null : s.trustedBrowsers,
        status: s.status,
        recoveryCodes: s.recoveryCodes,
        retryAfter: s.retryAfter,
        urls: urls.settings,
    };
}

/** Adding a method needs the account check: asked for, not passed, and no method yet. */
function needsVerification(s: State): boolean {
    return s.enrollmentEmail !== undefined && !s.enrollmentVerified && s.factors.length === 0;
}

/** Seconds until a new code can follow one used to verify (120s here), or null. */
function usedWait(s: State, f: Factor): number | null {
    const usedAt = s.codeUsedAt[f.id];
    const wait = usedAt === undefined ? 0 : 120 - Math.floor((Date.now() - usedAt) / 1000);
    return wait > 0 ? wait : null;
}

/** The challenge page's props, as ChallengeController::show() builds them. */
/** The challenge's steps when an enforced user holds several required types, else null. */
function challengeSteps(s: State): { total: number; passed: FactorType[] } | null {
    const held = s.requiredTypes.filter((type) => s.factors.some((f) => f.type === type));

    return held.length > 1 ? { total: held.length, passed: s.stepsPassed.filter((type) => held.includes(type)) } : null;
}

export function challengeProps(s: State) {
    // Each email/SMS factor's code still out, its cooldown (120s here), how long it stays valid (600s), and its code length.
    const sendState = (f: Factor) => {
        const sentAt = s.codeSentAt[f.id];
        const elapsed = sentAt === undefined ? 0 : Math.floor((Date.now() - sentAt) / 1000);
        if (sentAt === undefined || elapsed >= 600) return { code_sent: false, retry_after: usedWait(s, f), expires_in: null, code_length: 6 };
        const wait = 120 - elapsed;
        return { code_sent: true, retry_after: wait > 0 ? wait : null, expires_in: 600 - elapsed, code_length: 6 };
    };

    // An enforced user sees only the required types still to pass (Mfa::challengeRequirement()).
    const steps = challengeSteps(s);
    const shown = s.factors.filter((f) => s.requiredTypes.length === 0 || (s.requiredTypes.includes(f.type) && !s.stepsPassed.includes(f.type)));

    return {
        factors: shown.map((f) => ({ ...f, ...sendState(f) })),
        defaultFactorId: shown[0]?.id ?? null,
        steps,
        hasRecoveryCodes: s.recoveryCodesRemaining > 0,
        status: s.status,
        retryAfter: s.retryAfter,
        // null when off (the package also withholds it from enforced users unless trusted_browsers.allow_enforced).
        trustBrowser: s.trustBrowserDays === null ? null : { days: s.trustBrowserDays },
        renew: s.renew,
        urls: urls.challenge,
    };
}

/**
 * A QR-looking SVG (finder squares plus a pattern seeded by the secret). Not
 * scannable: the real one comes from the server's qr_svg.
 */
function sampleQr(seed: string): string {
    const n = 25;
    let x = [...seed].reduce((a, c) => (a * 31 + c.charCodeAt(0)) >>> 0, 7);
    const rand = () => ((x = (x * 1103515245 + 12345) >>> 0) >>> 16) & 1;
    const finder = (r: number, c: number) => {
        for (const [fr, fc] of [[0, 0], [0, n - 7], [n - 7, 0]]) {
            if (r >= fr && r < fr + 7 && c >= fc && c < fc + 7) {
                const dr = r - fr;
                const dc = c - fc;
                return dr === 0 || dr === 6 || dc === 0 || dc === 6 || (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4) ? 1 : 0;
            }
            if (r >= fr - 1 && r <= fr + 7 && c >= fc - 1 && c <= fc + 7) return 0;
        }
        return null;
    };
    let rects = '';
    for (let r = 0; r < n; r++) {
        for (let c = 0; c < n; c++) {
            const f = finder(r, c);
            if (f ?? rand()) rects += `<rect x="${c}" y="${r}" width="1" height="1"/>`;
        }
    }

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 ${n + 4} ${n + 4}" shape-rendering="crispEdges"><rect x="-2" y="-2" width="${n + 4}" height="${n + 4}" fill="#fff"/><g fill="#111">${rects}</g></svg>`;
}
