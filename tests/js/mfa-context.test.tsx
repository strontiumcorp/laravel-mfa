import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaEnableNudge from '../../stubs/inertia-react/components/enable-nudge';
import { mfaApiKeyNoticeProps, mfaNudgeProps, mfaSettingsCardProps, useMfa, useMfaNudge, type MfaContext } from '../../stubs/inertia-react/pages/mfa-context';
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
    ...overrides,
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
});
