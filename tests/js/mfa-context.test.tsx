import { act, render, screen } from '@testing-library/react';
import { useState } from 'react';
import MfaIdleWarning from '../../stubs/inertia-react/components/idle-warning';
import userEvent from '@testing-library/user-event';
import MfaEnableNudge from '../../stubs/inertia-react/components/enable-nudge';
import { mfaApiKeyNoticeProps, mfaNudgeProps, mfaSettingsCardProps, mfaReminderWhen, useMfa, useMfaIdleWarning, useMfaNudge, type MfaContext } from '../../stubs/inertia-react/pages/mfa-context';
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
    reverifyReminder: {
        show: false,
        reason: null,
        expiresAt: null,
        showAt: null,
        title: 'Two-factor check coming up',
        body: "This browser will ask for your sign-in code again :when. Do it now so it doesn't interrupt you later.",
        button: 'Verify now',
        dismissLabel: 'Later',
        verifyUrl: '/mfa/challenge?renew=1',
        dismissUrl: '/mfa/reminder/dismiss',
    },
    verification: null,
    ...overrides,
});
const NOW = new Date('2026-10-10T12:00:00Z').getTime();
/** A user with MFA on a trusted browser whose trust ends in `minutes`. */
const reminding = (minutes: number, overrides: Partial<MfaContext['reverifyReminder']> = {}) =>
    context({
        user: { hasMfa: true, verified: true, mustEnroll: false },
        nudge: { ...context().nudge, show: false },
        reverifyReminder: { ...context().reverifyReminder, show: true, reason: 'trust', expiresAt: new Date(Date.now() + minutes * 60_000).toISOString(), ...overrides },
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

describe('mfaReminderWhen', () => {
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
        expect(mfaReminderWhen(at(ms), NOW)).toBe(text);
    });

    it('says "soon" when it is past, now, unknown or invalid', () => {
        expect(mfaReminderWhen(at(-60_000), NOW)).toBe('soon');
        expect(mfaReminderWhen(at(0), NOW)).toBe('soon');
        expect(mfaReminderWhen(null, NOW)).toBe('soon');
        expect(mfaReminderWhen(undefined, NOW)).toBe('soon');
        expect(mfaReminderWhen('not a date', NOW)).toBe('soon');
    });
});

describe('the trusted browser reminder', () => {
    it('takes the copy, with :when filled in, and its own URLs', () => {
        expect(mfaNudgeProps(reminding(5 * 60 + 1))).toEqual({
            show: true,
            kind: 'reminder',
            title: 'Two-factor check coming up',
            body: "This browser will ask for your sign-in code again in 5 hours. Do it now so it doesn't interrupt you later.",
            button: 'Verify now',
            dismissLabel: 'Later',
            settingsUrl: '/mfa/challenge?renew=1',
            dismissUrl: '/mfa/reminder/dismiss',
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
        expect(mfaNudgeProps(reminding(30, { reason: null }))).toMatchObject({ show: false, kind: 'enable' });
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

        expect(inertia.requests).toEqual([{ method: 'post', url: '/mfa/reminder/dismiss', data: {} }]);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();
    });
});
});

describe('the reminder on a page opened before it is due', () => {
    afterEach(() => vi.useRealTimers());

    it('is due once showAt has come, without a new request', () => {
        const showAt = new Date(NOW + 60_000).toISOString();
        const ctx = reminding(30, { show: false, reason: 'lifetime', showAt, expiresAt: new Date(NOW + 30 * 60_000).toISOString() });

        expect(mfaNudgeProps(ctx, NOW).show).toBe(false);
        expect(mfaNudgeProps(ctx, NOW + 60_000)).toMatchObject({ show: true, kind: 'reminder' });
        expect(mfaNudgeProps(ctx, NOW + 60_000).body).toContain('again in 29 minutes.');
    });

    it('appears in the layout when it becomes due, and keeps :when current', async () => {
        vi.useFakeTimers({ now: NOW });
        inertia.pageProps.mfa = reminding(0, {
            show: false,
            reason: 'lifetime',
            showAt: new Date(NOW + 5 * 60_000).toISOString(),
            expiresAt: new Date(NOW + 35 * 60_000).toISOString(),
        });
        function Layout() {
            return <MfaEnableNudge {...useMfaNudge()} />;
        }
        render(<Layout />);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();

        await act(async () => vi.advanceTimersByTime(5 * 60_000));
        expect(screen.getByRole('region', { name: 'Two-factor check coming up' })).toHaveTextContent('again in 30 minutes.');

        await act(async () => vi.advanceTimersByTime(60_000));
        expect(screen.getByRole('region')).toHaveTextContent('again in 29 minutes.');
    });

    it('never schedules past what setTimeout can hold (a trust reminder weeks away)', () => {
        vi.useFakeTimers({ now: NOW });
        const spy = vi.spyOn(globalThis, 'setTimeout');
        inertia.pageProps.mfa = reminding(0, { show: false, showAt: new Date(NOW + 29 * 86_400_000).toISOString() });
        function Layout() {
            return <MfaEnableNudge {...useMfaNudge()} />;
        }
        render(<Layout />);

        expect(spy.mock.calls.every(([, ms]) => (ms ?? 0) <= 2 ** 31 - 1)).toBe(true);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();
    });
});

describe('useMfaIdleWarning', () => {
    // The server's clock matches the browser's (NOW) unless a test says otherwise.
    const verification = {
        profile: 'enforced',
        now: '2026-10-10T12:00:00Z',
        expiresAt: '2026-10-10T16:00:00Z',
        remindAt: '2026-10-10T15:30:00Z',
        graceUntil: '2026-10-10T16:10:00Z',
        idleSeconds: 1500,
        idleExpiresAt: '2026-10-10T12:25:00Z',
        renewUrl: '/mfa/challenge?renew=1',
        keepAliveUrl: '/mfa/session/keep-alive',
        stateUrl: '/mfa/session',
    };
    beforeEach(() => vi.useFakeTimers({ now: NOW, toFake: ['Date'] }));
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('has nothing to watch without an idle timeout, or for an unverified session', () => {
        const watched = (mfa: MfaContext) => {
            inertia.pageProps.mfa = mfa;
            let at: string | null = 'unset';
            function Probe() {
                at = useMfaIdleWarning().idleExpiresAt;
                return null;
            }
            render(<Probe />);

            return at;
        };

        expect(watched(context({ user: { hasMfa: true, verified: true, mustEnroll: false } }))).toBeNull();
        expect(watched(context({ user: { hasMfa: true, verified: false, mustEnroll: false }, verification }))).toBeNull();
        expect(watched(context({ user: { hasMfa: true, verified: true, mustEnroll: false }, verification: { ...verification, keepAliveUrl: null } }))).toBeNull();
        expect(watched(context({ user: { hasMfa: true, verified: true, mustEnroll: false }, verification }))).toBe('2026-10-10T12:25:00.000Z');
    });

    it("moves the server's deadlines onto the browser's clock", () => {
        inertia.pageProps.mfa = context({ user: { hasMfa: true, verified: true, mustEnroll: false }, verification: { ...verification, now: '2026-10-10T11:30:00Z' } });
        let at: string | null = null;
        function Probe() {
            at = useMfaIdleWarning().idleExpiresAt;
            return null;
        }
        render(<Probe />);

        // The browser is 30 minutes ahead: the 25 minutes left are 25 minutes on its clock too.
        expect(at).toBe('2026-10-10T12:55:00.000Z');
    });

    it('stays signed in with a keep-alive POST (XSRF header), then reads the new deadline', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const fetch = vi.fn(async (url: string, _init?: RequestInit) =>
            url === '/mfa/session'
                ? new Response(JSON.stringify({ verification: { ...verification, idleExpiresAt: '2026-10-10T12:50:00Z' } }), { status: 200 })
                : new Response(null, { status: 204 }),
        );
        vi.stubGlobal('fetch', fetch);
        inertia.pageProps.mfa = context({ user: { hasMfa: true, verified: true, mustEnroll: false }, verification });

        let props!: ReturnType<typeof useMfaIdleWarning>;
        function Probe() {
            props = useMfaIdleWarning();
            return null;
        }
        render(<Probe />);

        expect(props.idleExpiresAt).toBe('2026-10-10T12:25:00.000Z');
        await expect(props.onStay()).resolves.toBe('2026-10-10T12:50:00.000Z');
        expect(fetch.mock.calls[0][0]).toBe('/mfa/session/keep-alive');
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST', headers: { 'X-XSRF-TOKEN': 'abc=' } });
        expect(fetch.mock.calls[1][0]).toBe('/mfa/session');
    });

    it('keeps the deadline stable while the layout re-renders, so the warning still shows', async () => {
        vi.useRealTimers();
        vi.useFakeTimers({ now: NOW });
        inertia.pageProps.mfa = context({ user: { hasMfa: true, verified: true, mustEnroll: false }, verification });
        let rerender!: () => void;
        function Layout() {
            const [, setN] = useState(0);
            rerender = () => setN((n) => n + 1);
            return <MfaIdleWarning {...useMfaIdleWarning()} />;
        }
        render(<Layout />);

        for (let minute = 1; minute <= 23; minute++) {
            await act(async () => void (await vi.advanceTimersByTimeAsync(60_000)));
            act(() => rerender()); // e.g. the reminder's minute clock
        }

        expect(screen.getByRole('region', { name: 'Still there?' })).toBeInTheDocument();
    });

    it('reports a failed keep-alive as null (the session already ended)', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 403 })));
        inertia.pageProps.mfa = context({ user: { hasMfa: true, verified: true, mustEnroll: false }, verification });
        let props!: ReturnType<typeof useMfaIdleWarning>;
        function Probe() {
            props = useMfaIdleWarning();
            return null;
        }
        render(<Probe />);

        await expect(props.onStay()).resolves.toBeNull();
        await expect(props.refresh()).resolves.toBeNull();
    });
});
