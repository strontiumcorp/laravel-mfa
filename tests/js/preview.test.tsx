import { render, screen } from '@testing-library/react';
import { CODE, challengeProps, handle, initialState, PASSWORD, settingsProps } from '../../preview/backend';
import { scenarios } from '../../preview/scenarios';
import MfaChallenge from '../../stubs/inertia-react/pages/challenge';
import MfaSettings from '../../stubs/inertia-react/pages/settings';

vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);

// `make preview` must keep working as the pages change: every scenario renders,
// and the fake backend follows the package's rules.
describe('UI preview', () => {
    it.each(scenarios.filter((s) => s.page === 'settings' || s.page === 'challenge'))('renders the "$title" scenario', ({ page, state }) => {
        const s = state();
        render(page === 'challenge' ? <MfaChallenge {...challengeProps(s)} /> : <MfaSettings {...settingsProps(s)} />);

        expect(screen.getAllByRole('heading').length).toBeGreaterThan(0);
    });

    it('walks an authenticator app from "Set up" to active, with recovery codes', () => {
        const s = initialState();

        expect(handle(s, 'post', '/mfa/factors', { type: 'totp' })).toEqual({});
        const [pending] = s.pending;
        expect(pending.secret).toBeTruthy();
        expect(pending.otpauth_url).toMatch(/^otpauth:\/\/totp\//);

        expect(handle(s, 'post', `/mfa/factors/${pending.id}/confirm`, { code: '000000' }).errors).toEqual({ code: 'The provided code is invalid.' });
        expect(handle(s, 'post', `/mfa/factors/${pending.id}/confirm`, { code: CODE })).toEqual({});
        expect(s.factors.map((f) => f.type)).toEqual(['totp']);
        expect(settingsProps(s).recoveryCodes).toHaveLength(10);
    });

    it('takes "Not today" on the nudge, and offers the settings notice to users without a method', () => {
        const s = initialState();
        expect(settingsProps(s).nudge).toMatchObject({ title: 'Protect your account' });

        expect(handle(s, 'post', '/mfa/nudge/dismiss', { timezone: 'Asia/Dhaka' }).errors).toBeUndefined();
        expect(s.nudgeDismissed).toBe(true);
        expect(settingsProps(initialState({ mustEnroll: true })).nudge).toBeNull();
    });

    it('asks for the password first when the scenario says so', () => {
        const s = initialState({ requirePassword: true });

        expect(handle(s, 'post', '/mfa/factors', { type: 'totp' }).errors).toHaveProperty('password_confirmation_required');
        expect(handle(s, 'post', '/mfa/confirm-password', { password: PASSWORD })).toEqual({});
        expect(handle(s, 'post', '/mfa/factors', { type: 'totp' })).toEqual({});
    });
});
