import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaEnableNudge from '../../stubs/inertia-react/components/enable-nudge';
import { mfaApiKeyNoticeProps, mfaNudgeProps, mfaSettingsCardProps, mfaTrustReminderWhen, useMfa, useMfaNudge, type MfaContext } from '../../stubs/inertia-react/pages/mfa-context';
import { inertia } from './inertia-mock';

vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);

const context = (overrides: Partial<MfaContext> = {}): MfaContext => ({
    enabled: true,
    factors: ['totp', 'email'],
    passwordConfirmation: true,
    user: { hasMfa: false, verified: false, mustEnroll: false },
    urls: { settings: '/mfa/settings', challenge: '/mfa/challenge' },
    nudge: {
        show: true,
        title: 'Protect your account',
        body: 'Turn on two-factor sign-in now.',
        button: 'Turn on',
        dismissLabel: 'Not today',
        dismissUrl: '/mfa/nudge/dismiss',
    },
    trustReminder: {
        show: false,
        expiresAt: null,
        title: 'Two-factor check coming up',
        body: "This browser will ask for your sign-in code again :when. Do it now so it doesn't interrupt you later.",
        button: 'Verify now',
        dismissLabel: 'Later',
        verifyUrl: '/mfa/challenge?renew=1',
        dismissUrl: '/mfa/trusted-browsers/reminder/dismiss',
    },
    ...overrides,
});
const NOW = new Date('2026-10-10T12:00:00Z').getTime();
/** A user with MFA on a trusted browser whose trust ends in `minutes`. */
const reminding = (minutes: number, overrides: Partial<MfaContext['trustReminder']> = {}) =>
    context({
        user: { hasMfa: true, verified: true, mustEnroll: false },
        nudge: { ...context().nudge, show: false },
        trustReminder: { ...context().trustReminder, show: true, expiresAt: new Date(Date.now() + minutes * 60_000).toISOString(), ...overrides },
    });

beforeEach(() => inertia.reset());

describe('useMfa', () => {
    it('reads the shared "mfa" prop, or null when the app does not share it', () => {
        expect(useMfa()).toBeNull();

        inertia.pageProps.mfa = context();
        expect(useMfa()).toEqual(context());
    });
});

describe('mfaSettingsCardProps', () => {
    it('takes the state and the settings URL from the context', () => {
        expect(mfaSettingsCardProps(context({ user: { hasMfa: true, verified: true, mustEnroll: false } }))).toEqual({
            enabled: true,
            settingsUrl: '/mfa/settings',
            hasMfa: true,
            mustEnroll: false,
        });
        expect(mfaSettingsCardProps(context({ user: { hasMfa: false, verified: true, mustEnroll: true } }))).toMatchObject({ mustEnroll: true });
    });

    it('hides the card when MFA is off, the routes are off, or there is no context', () => {
        expect(mfaSettingsCardProps(context({ enabled: false })).enabled).toBe(false);
        expect(mfaSettingsCardProps(context({ urls: { settings: null, challenge: null } })).settingsUrl).toBeNull();
        expect(mfaSettingsCardProps(null)).toEqual({ enabled: false, settingsUrl: null, hasMfa: false, mustEnroll: false });
    });
});

describe('mfaApiKeyNoticeProps', () => {
    it('links to the settings page only for a user without MFA', () => {
        expect(mfaApiKeyNoticeProps(context())).toEqual({ enabled: true, settingsUrl: '/mfa/settings' });
        expect(mfaApiKeyNoticeProps(context({ user: { hasMfa: true, verified: true, mustEnroll: false } })).settingsUrl).toBeNull();
        expect(mfaApiKeyNoticeProps(null)).toEqual({ enabled: true, settingsUrl: null });
    });
});

describe('mfaNudgeProps', () => {
    it('takes the copy and the URLs from the context', () => {
        expect(mfaNudgeProps(context())).toEqual({
            show: true,
            title: 'Protect your account',
            body: 'Turn on two-factor sign-in now.',
            button: 'Turn on',
            dismissLabel: 'Not today',
            settingsUrl: '/mfa/settings',
            dismissUrl: '/mfa/nudge/dismiss',
            kind: 'enable',
            closeLabel: 'Dismiss for today',
        });
    });

    it('hides it when the server says so, MFA or its routes are off, or there is no context', () => {
        expect(mfaNudgeProps(context({ nudge: { ...context().nudge, show: false } })).show).toBe(false);
        expect(mfaNudgeProps(context({ enabled: false })).show).toBe(false);
        expect(mfaNudgeProps(context({ urls: { settings: null, challenge: null }, nudge: { ...context().nudge, dismissUrl: null } })).show).toBe(false);
        expect(mfaNudgeProps(null)).toMatchObject({ show: false, settingsUrl: null, dismissUrl: null });
    });
});

describe('useMfaNudge', () => {
    function Layout() {
        return <MfaEnableNudge {...useMfaNudge()} />;
    }

    it('mounts the nudge from the shared context alone, with Inertia\'s <Link>', () => {
        inertia.pageProps.mfa = context();
        render(<Layout />);

        expect(screen.getByRole('region', { name: 'Protect your account' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Turn on' })).toHaveAttribute('href', '/mfa/settings');
        expect(screen.getByRole('link', { name: 'Turn on' })).toHaveAttribute('data-inertia-link');
    });

    it('posts the browser timezone to the dismiss URL, staying on the page', async () => {
        inertia.pageProps.mfa = context();
        render(<Layout />);

        await userEvent.click(screen.getByRole('button', { name: 'Not today' }));

        expect(inertia.requests).toEqual([{ method: 'post', url: '/mfa/nudge/dismiss', data: { timezone: Intl.DateTimeFormat().resolvedOptions().timeZone } }]);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();
    });

    it('takes disabled next to it (impersonation): hides on dismiss, sends nothing', async () => {
        inertia.pageProps.mfa = context();
        function ImpersonatedLayout() {
            return <MfaEnableNudge {...useMfaNudge()} disabled />;
        }
        render(<ImpersonatedLayout />);

        expect(screen.getByRole('link', { name: 'Turn on' })).toHaveAttribute('data-inertia-link');
        await userEvent.click(screen.getByRole('button', { name: 'Not today' }));

        expect(screen.queryByRole('region')).not.toBeInTheDocument();
        expect(inertia.requests).toEqual([]);
    });

    it('renders nothing without a shared context', () => {
        const { container } = render(<Layout />);

        expect(container).toBeEmptyDOMElement();
    });

describe('mfaTrustReminderWhen', () => {
    const at = (ms: number) => new Date(NOW + ms).toISOString();

    it.each([
        [5 * 3600_000, 'in 5 hours'],
        [5 * 3600_000 - 1_000, 'in 4 hours'],
        [2 * 3600_000 - 1_000, 'in an hour'],
        [3600_000, 'in an hour'],
        [3600_000 - 1_000, 'in 59 minutes'],
        [40 * 60_000, 'in 40 minutes'],
        [40 * 60_000 - 1_000, 'in 39 minutes'],
        [2 * 60_000, 'in 2 minutes'],
        [2 * 60_000 - 1_000, 'in a minute'],
        [1_000, 'in a minute'],
    ])('rounds %i ms down to "%s", never promising more time than is left', (ms, text) => {
        expect(mfaTrustReminderWhen(at(ms), NOW)).toBe(text);
    });

    it('says "soon" when it is past, now, unknown or invalid', () => {
        expect(mfaTrustReminderWhen(at(-60_000), NOW)).toBe('soon');
        expect(mfaTrustReminderWhen(at(0), NOW)).toBe('soon');
        expect(mfaTrustReminderWhen(null, NOW)).toBe('soon');
        expect(mfaTrustReminderWhen(undefined, NOW)).toBe('soon');
        expect(mfaTrustReminderWhen('not a date', NOW)).toBe('soon');
    });
});

describe('the trusted browser reminder', () => {
    it('takes the copy, with :when filled in, and its own URLs', () => {
        expect(mfaNudgeProps(reminding(5 * 60 + 1))).toEqual({
            show: true,
            kind: 'trust-reminder',
            title: 'Two-factor check coming up',
            body: "This browser will ask for your sign-in code again in 5 hours. Do it now so it doesn't interrupt you later.",
            button: 'Verify now',
            dismissLabel: 'Later',
            settingsUrl: '/mfa/challenge?renew=1',
            dismissUrl: '/mfa/trusted-browsers/reminder/dismiss',
            closeLabel: 'Dismiss',
        });
        expect(mfaNudgeProps(reminding(-1)).body).toContain('again soon.');
    });

    it('never says "renew"', () => {
        expect(JSON.stringify(mfaNudgeProps(reminding(30)))).not.toMatch(/renew(?!=1)/i);
    });

    it('yields to the turn-on nudge, and hides when the server, MFA or the routes say so', () => {
        const both = reminding(30);
        both.nudge.show = true;
        expect(mfaNudgeProps(both)).toMatchObject({ kind: 'enable', title: 'Protect your account' });

        expect(mfaNudgeProps(reminding(30, { show: false }))).toMatchObject({ show: false, kind: 'enable' });
        expect(mfaNudgeProps({ ...reminding(30), enabled: false }).show).toBe(false);
        expect(mfaNudgeProps(reminding(30, { verifyUrl: null })).show).toBe(false);
        expect(mfaNudgeProps(reminding(30, { dismissUrl: null })).show).toBe(false);
    });

    it('mounts in the layout: "Verify now" links to the challenge, "Later" posts nothing to its dismiss URL', async () => {
        inertia.pageProps.mfa = reminding(40.5);
        function Layout() {
            return <MfaEnableNudge {...useMfaNudge()} />;
        }
        render(<Layout />);

        expect(screen.getByRole('region', { name: 'Two-factor check coming up' })).toHaveTextContent('again in 40 minutes.');
        expect(screen.getByRole('link', { name: 'Verify now' })).toHaveAttribute('href', '/mfa/challenge?renew=1');
        expect(screen.getByRole('button', { name: 'Dismiss' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Later' }));

        expect(inertia.requests).toEqual([{ method: 'post', url: '/mfa/trusted-browsers/reminder/dismiss', data: {} }]);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();
    });
});
});
