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

export type State = {
    factors: Factor[];
    pending: Factor[];
    recoveryCodesRemaining: number;
    mustEnroll: boolean;
    requiredTypes: FactorType[];
    enabledTypes: FactorType[];
    /** Factor changes need the password (routes.password_confirmation). */
    requirePassword: boolean;
    passwordConfirmed: boolean;
    passwordAttempts: number;
    /** "Not today" on the nudge was clicked (until the frame reloads). */
    nudgeDismissed: boolean;
    /** Challenge: when each email/SMS factor's code went out (ms), for its send state. */
    codeSentAt: Record<number, number>;
    /** Challenge: when a code was last used to verify (ms): the next send waits 120s from then (the cooldown spans logins). */
    codeUsedAt: Record<number, number>;
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
    },
    challenge: { send: '/mfa/challenge/send', verify: '/mfa/challenge', recover: '/mfa/challenge/recover', logout: '/logout' },
    nudgeDismiss: '/mfa/nudge/dismiss',
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

export function initialState(overrides: Partial<State> = {}): State {
    return {
        factors: [],
        pending: [],
        recoveryCodesRemaining: 0,
        mustEnroll: false,
        requiredTypes: [],
        enabledTypes: ['totp', 'email', 'sms'],
        requirePassword: false,
        passwordConfirmed: false,
        passwordAttempts: 0,
        codeSentAt: {},
        codeUsedAt: {},
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

    if (method === 'post' && url === urls.settings.store) {
        if (needsPassword()) return passwordRequired;
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
        return data.code === CODE ? { toast: 'Verified. The app would now open the page the user asked for.' } : { errors: { code: 'The provided code is invalid.' } };
    }

    if (method === 'post' && url === urls.challenge.recover) {
        return String(data.code).trim().toLowerCase() === RECOVERY_CODE
            ? { toast: 'Verified with a recovery code. 9 left.' }
            : { errors: { code: 'The provided code is invalid.' } };
    }

    if (method === 'post' && url === urls.challenge.logout) return { toast: 'Signed out (preview).' };

    if (method === 'post' && url === urls.nudgeDismiss) {
        s.nudgeDismissed = true;
        return { toast: `Hidden until midnight in ${String(data.timezone ?? 'the app timezone')} (preview).` };
    }

    return { toast: `No preview handler for ${method.toUpperCase()} ${url}` };
}

/** The settings page's props, as SettingsController::show() builds them. */
export function settingsProps(s: State) {
    const available = s.enabledTypes.map((type) => ({ type, label: LABELS[type], recommended: type === 'totp' }));

    return {
        factors: s.factors,
        pending: s.pending,
        availableTypes: [...available].sort((a, b) => Number(b.recommended) - Number(a.recommended)),
        recoveryCodesRemaining: s.recoveryCodesRemaining,
        recoveryCodesTotal: 10,
        mustEnroll: s.mustEnroll,
        requiredTypes: s.mustEnroll || s.requiredTypes.length > 0 ? s.requiredTypes.map((type) => ({ type, label: LABELS[type] })) : [],
        passwordRetryAfter: s.passwordRetryAfter,
        passwordConfirmationRequired: s.requirePassword && !s.passwordConfirmed,
        nudge: s.factors.length === 0 && !s.mustEnroll ? { title: NUDGE.title, body: NUDGE.body } : null,
        status: s.status,
        recoveryCodes: s.recoveryCodes,
        retryAfter: s.retryAfter,
        urls: urls.settings,
    };
}

/** Seconds until a new code can follow one used to verify (120s here), or null. */
function usedWait(s: State, f: Factor): number | null {
    const usedAt = s.codeUsedAt[f.id];
    const wait = usedAt === undefined ? 0 : 120 - Math.floor((Date.now() - usedAt) / 1000);
    return wait > 0 ? wait : null;
}

/** The challenge page's props, as ChallengeController::show() builds them. */
export function challengeProps(s: State) {
    // Each email/SMS factor's code still out, its cooldown (120s here), how long it stays valid (600s), and its code length.
    const sendState = (f: Factor) => {
        const sentAt = s.codeSentAt[f.id];
        const elapsed = sentAt === undefined ? 0 : Math.floor((Date.now() - sentAt) / 1000);
        if (sentAt === undefined || elapsed >= 600) return { code_sent: false, retry_after: usedWait(s, f), expires_in: null, code_length: 6 };
        const wait = 120 - elapsed;
        return { code_sent: true, retry_after: wait > 0 ? wait : null, expires_in: 600 - elapsed, code_length: 6 };
    };

    return {
        factors: s.factors.map((f) => ({ ...f, ...sendState(f) })),
        defaultFactorId: s.factors[0]?.id ?? null,
        hasRecoveryCodes: s.recoveryCodesRemaining > 0,
        status: s.status,
        retryAfter: s.retryAfter,
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
