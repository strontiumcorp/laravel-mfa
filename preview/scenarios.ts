// The states the preview can open in. Each one is a starting point: the fake
// backend (backend.ts) takes it from there as you click through.
import { factor, initialState, pendingTotp, type State } from './backend';

export type Page = 'settings' | 'challenge' | 'account';

export type Scenario = { id: string; page: Page; title: string; state: () => State };

const totp = () => factor('totp', { last_used_at: '2026-10-09T08:00:00Z' });
const email = () => factor('email', { confirmed_at: '2026-10-05T10:00:00Z' });
const sms = () => factor('sms', { confirmed_at: '2026-10-06T10:00:00Z' });

export const scenarios: Scenario[] = [
    { id: 'new', page: 'settings', title: 'Settings · nothing set up', state: () => initialState() },
    { id: 'enforced', page: 'settings', title: 'Settings · enforced, needs an authenticator app', state: () => initialState({ mustEnroll: true, requiredTypes: ['totp'] }) },
    { id: 'totp-setup', page: 'settings', title: 'Settings · setting up an authenticator app', state: () => initialState({ pending: [pendingTotp()] }) },
    {
        id: 'sms-setup',
        page: 'settings',
        title: 'Settings · setting up SMS',
        state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 8, pending: [factor('sms', { confirmed: false, confirmed_at: null })], retryAfter: 102 }),
    },
    { id: 'active', page: 'settings', title: 'Settings · two methods on', state: () => initialState({ factors: [totp(), email()], recoveryCodesRemaining: 8 }) },
    { id: 'low-codes', page: 'settings', title: 'Settings · 2 recovery codes left', state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 2 }) },
    {
        id: 'new-codes',
        page: 'settings',
        title: 'Settings · new recovery codes shown',
        state: () => {
            const s = initialState({ factors: [totp()], recoveryCodesRemaining: 10 });
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
    { id: 'challenge', page: 'challenge', title: 'Challenge · authenticator app and email', state: () => initialState({ factors: [totp(), email()], recoveryCodesRemaining: 8 }) },
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
    { id: 'challenge-email', page: 'challenge', title: 'Challenge · email only', state: () => initialState({ factors: [email()], recoveryCodesRemaining: 8 }) },
    { id: 'challenge-totp', page: 'challenge', title: 'Challenge · authenticator app only, no recovery codes', state: () => initialState({ factors: [totp()] }) },
    { id: 'account', page: 'account', title: 'Account settings · card and API-key notice', state: () => initialState({ factors: [totp()], recoveryCodesRemaining: 8 }) },
];
