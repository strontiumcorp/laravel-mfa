import { mfaApiKeyNoticeProps, mfaSettingsCardProps, useMfa, type MfaContext } from '../../stubs/inertia-react/pages/mfa-context';
import { inertia } from './inertia-mock';

vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);

const context = (overrides: Partial<MfaContext> = {}): MfaContext => ({
    enabled: true,
    factors: ['totp', 'email'],
    passwordConfirmation: true,
    user: { hasMfa: false, verified: false, mustEnroll: false },
    urls: { settings: '/mfa/settings', challenge: '/mfa/challenge' },
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
