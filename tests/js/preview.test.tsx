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

    it('asks an enforced user to confirm it is them before the first method', () => {
        const s = initialState({ mustEnroll: true, enrollmentEmail: 'j***@example.com' });
        expect(settingsProps(s).enrollmentVerification).toEqual({ email: 'j***@example.com' });

        expect(handle(s, 'post', '/mfa/factors', { type: 'totp' }).errors).toHaveProperty('enrollment_verification_required');
        expect(handle(s, 'post', '/mfa/enrollment-verification/send', {}).errors).toBeUndefined();
        expect(settingsProps(s).retryAfter).toBe(60);
        expect(handle(s, 'post', '/mfa/enrollment-verification/send', {}).errors).toEqual({ code: 'Please wait before requesting another code.' });
        expect(handle(s, 'post', '/mfa/enrollment-verification', { code: '000000' }).errors).toEqual({ code: 'The provided code is invalid.' });
        expect(handle(s, 'post', '/mfa/enrollment-verification', { code: CODE })).toEqual({});
        expect(settingsProps(s).enrollmentVerification).toBeNull();
        expect(handle(s, 'post', '/mfa/factors', { type: 'totp' })).toEqual({});

        const link = initialState({ mustEnroll: true, enrollmentEmail: null });
        expect(settingsProps(link).enrollmentVerification).toEqual({ email: null });
        expect(handle(link, 'post', '/mfa/enrollment-verification/send', {}).errors).toHaveProperty('code');
    });

    it('asks for the password first when the scenario says so', () => {
        const s = initialState({ requirePassword: true });

        expect(handle(s, 'post', '/mfa/factors', { type: 'totp' }).errors).toHaveProperty('password_confirmation_required');
        expect(handle(s, 'post', '/mfa/confirm-password', { password: PASSWORD })).toEqual({});
        expect(handle(s, 'post', '/mfa/factors', { type: 'totp' })).toEqual({});
    });

    it('remembers a browser on request, and forgets one or all', () => {
        const s = initialState({ factors: [], trustBrowserDays: 30 });
        expect(settingsProps(s).trustedBrowsers).toEqual([]);
        expect(challengeProps(s).trustBrowser).toEqual({ days: 30 });
        expect(challengeProps(initialState()).trustBrowser).toBeNull();
        expect(settingsProps(initialState()).trustedBrowsers).toBeNull();

        handle(s, 'post', '/mfa/challenge', { factor_id: 1, code: CODE });
        expect(s.trustedBrowsers).toEqual([]);
        handle(s, 'post', '/mfa/challenge', { factor_id: 1, code: CODE, remember: true });
        handle(s, 'post', '/mfa/challenge', { factor_id: 1, code: CODE, remember: true });
        expect(s.trustedBrowsers.map((b) => b.current)).toEqual([true, false]);

        expect(handle(s, 'delete', `/mfa/trusted-browsers/${s.trustedBrowsers[1].id}`, {})).toEqual({});
        expect(s.trustedBrowsers).toHaveLength(1);
        expect(settingsProps(s).status).toBe('trusted-browsers-forgotten');
        handle(s, 'post', '/mfa/challenge', { factor_id: 1, code: CODE, remember: true });
        expect(handle(s, 'delete', '/mfa/trusted-browsers', {})).toEqual({});
        expect(s.trustedBrowsers).toEqual([]);
    });

    it('hides the "check coming up" reminder on "Later"', () => {
        const s = initialState({ factors: [], trustEndsInMinutes: 60 });

        expect(handle(s, 'post', '/mfa/reminder/dismiss', {})).toEqual({});
        expect(s.reminderDismissed).toBe(true);
        expect(challengeProps(initialState({ renew: true })).renew).toBe(true);
        expect(challengeProps(initialState()).renew).toBe(false);
    });

    it('keeps the idle timeout alive until it has ended', () => {
        const s = initialState({ idleExpiresAt: Date.now() + 60_000 });

        expect(handle(s, 'post', '/mfa/session/keep-alive', {})).toEqual({});
        expect(s.idleExpiresAt).toBeGreaterThan(Date.now() + 24 * 60_000);

        s.idleExpiresAt = Date.now() - 1;
        expect(handle(s, 'post', '/mfa/session/keep-alive', {}).errors).toBeDefined();
    });
});
