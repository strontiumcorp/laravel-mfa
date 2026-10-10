// The states the preview can open in. Each one is a starting point: the fake
// backend (backend.ts) takes it from there as you click through.
import { factor, initialState, pendingTotp, trustedBrowser, type State } from './backend';

export type Page = 'settings' | 'challenge' | 'account' | 'app';

export type Scenario = { id: string; page: Page; title: string; state: () => State };

const totp = () => factor('totp', { last_used_at: '2026-10-09T08:00:00Z' });
const email = () => factor('email', { confirmed_at: '2026-10-05T10:00:00Z' });
const sms = () => factor('sms', { confirmed_at: '2026-10-06T10:00:00Z' });

export const scenarios: Scenario[] = [
    { id: 'new', page: 'settings', title: 'Settings · nothing set up', state: () => initialState() },
    { id: 'enforced', page: 'settings', title: 'Settings · enforced, needs an authenticator app', state: () => initialState({ mustEnroll: true, requiredTypes: ['totp'] }) },
    {
        id: 'enforced-check',
        page: 'settings',
        title: 'Settings · enforced user, first method (email check)',
        state: () => initialState({ mustEnroll: true, requiredTypes: ['totp'], enrollmentEmail: 'j***@example.com' }),
    },
    {
        id: 'enforced-link',
        page: 'settings',
        title: 'Settings · enforced user, first method (needs an administrator link)',
        state: () => initialState({ mustEnroll: true, requiredTypes: ['totp'], enrollmentEmail: null }),
    },
    { id: 'totp-setup', page: 'settings', title: 'Settings · setting up an authenticator app', state: () => initialState({ pending: [pendingTotp()] }) },
    {
        id: 'sms-setup',
        page: 'settings',
        title: 'Settings · setting up SMS',
        state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 8, pending: [factor('sms', { confirmed: false, confirmed_at: null })], retryAfter: 102 }),
    },
    { id: 'active', page: 'settings', title: 'Settings · two methods on', state: () => initialState({ factors: [totp(), email()], recoveryCodesRemaining: 8 }) },
    {
        id: 'trusted',
        page: 'settings',
        title: 'Settings · trusted browsers (this one and two others)',
        state: () =>
            initialState({
                factors: [totp(), email()],
                recoveryCodesRemaining: 8,
                trustBrowserDays: 30,
                trustedBrowsers: [
                    trustedBrowser('Chrome on Mac', 2, { current: true, last_used_at: new Date(Date.now() - 3 * 3_600_000).toISOString() }),
                    trustedBrowser('Safari on iPhone', 9, { last_used_at: new Date(Date.now() - 86_400_000).toISOString() }),
                    trustedBrowser(null, 20),
                ],
            }),
    },
    { id: 'trusted-none', page: 'settings', title: 'Settings · trusted browsers on, none yet', state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 8, trustBrowserDays: 30 }) },
    { id: 'low-codes', page: 'settings', title: 'Settings · 2 recovery codes left', state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 2 }) },
    {
        id: 'new-codes',
        page: 'settings',
        title: 'Settings · new recovery codes shown (dialog)',
        state: () => {
            const s = initialState({ factors: [totp()], recoveryCodesRemaining: 10 });
            // As after making them: the page opens the dialog on its codes.
            s.status = 'recovery-codes-generated';
            s.recoveryCodes = ['aaaaa-11111', 'bbbbb-22222', 'ccccc-33333', 'ddddd-44444', 'eeeee-55555', 'fffff-66666', 'ggggg-77777', 'hhhhh-88888', 'iiiii-99999', 'jjjjj-10101'];
            return s;
        },
    },
    {
        id: 'password',
        page: 'settings',
        title: 'Settings · changes ask for the password',
        state: () => initialState({ factors: [totp(), email()], recoveryCodesRemaining: 8, requirePassword: true }),
    },
    {
        id: 'challenge',
        page: 'challenge',
        title: "Challenge · authenticator app and email, with \"don't ask again\"",
        state: () => initialState({ factors: [totp(), email()], recoveryCodesRemaining: 8, trustBrowserDays: 30 }),
    },
    {
        id: 'challenge-all',
        page: 'challenge',
        title: 'Challenge · email first, with an authenticator app, SMS and recovery codes',
        state: () => initialState({ factors: [email(), totp(), sms()], recoveryCodesRemaining: 8 }),
    },
    {
        id: 'challenge-sent',
        page: 'challenge',
        title: 'Challenge · email code already sent (after a refresh)',
        state: () => {
            const f = email();
            // Sent 62 seconds ago: the countdown shows 0:58.
            return initialState({ factors: [f, totp()], recoveryCodesRemaining: 8, codeSentAt: { [f.id]: Date.now() - 62_000 } });
        },
    },
    {
        id: 'challenge-used',
        page: 'challenge',
        title: 'Challenge · email code used moments ago (logged in again)',
        state: () => {
            const f = email();
            // Used 30 seconds ago: the next code waits 1:30, then the page sends it.
            return initialState({ factors: [f, totp()], recoveryCodesRemaining: 8, codeUsedAt: { [f.id]: Date.now() - 30_000 } });
        },
    },
    { id: 'challenge-email', page: 'challenge', title: 'Challenge · email only', state: () => initialState({ factors: [email()], recoveryCodesRemaining: 8 }) },
    { id: 'challenge-totp', page: 'challenge', title: 'Challenge · authenticator app only, no recovery codes', state: () => initialState({ factors: [totp()] }) },
    {
        id: 'challenge-renew',
        page: 'challenge',
        title: 'Challenge · verifying early from the trusted browser reminder',
        state: () => initialState({ factors: [totp(), email()], recoveryCodesRemaining: 8, trustBrowserDays: 30, renew: true }),
    },
    {
        id: 'trust-reminder',
        page: 'app',
        title: 'App page · trusted browser reminder (this browser asks again in 5 hours)',
        state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 8, trustBrowserDays: 30, trustEndsInMinutes: 5 * 60 - 10 }),
    },
    { id: 'nudge', page: 'app', title: 'App page · nudge to turn two-factor on (no method yet)', state: () => initialState() },
    { id: 'account', page: 'account', title: 'Account settings · card and API-key notice', state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 8 }) },
];
