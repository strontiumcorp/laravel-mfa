import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StrictMode } from 'react';
import MfaChallenge from '../../stubs/inertia-react/pages/challenge';
import { inertia } from './inertia-mock';

vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);

const factors = [
    { id: 1, type: 'totp' as const, type_label: 'Authenticator app', label: null, destination: null },
    { id: 2, type: 'email' as const, type_label: 'Email', label: null, destination: 'j***@example.com' },
    { id: 3, type: 'sms' as const, type_label: 'SMS', label: null, destination: '+*******0100' },
];
const urls = { send: '/mfa/challenge/send', verify: '/mfa/challenge/verify', recover: '/mfa/challenge/recover', logout: '/logout' };
/** The factors, with a code already out for one of them (as after a refresh). */
const withSent = (id: number, retryAfter: number | null) => factors.map((f) => (f.id === id ? { ...f, code_sent: true, retry_after: retryAfter } : f));
const props = { factors, defaultFactorId: 1, hasRecoveryCodes: true, status: null, retryAfter: null, trustBrowser: null, renew: false, urls };
/** "Try another way", then the method whose row starts with this name. */
const choose = async (name: string) => {
    await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));
    await userEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }));
};
/** The line under the title. */
const description = () => document.querySelector('[aria-live="polite"]');

beforeEach(() => inertia.reset());

describe('challenge page', () => {
    it('shows the step an enforced user is on, and moves on to the next method after one is accepted', async () => {
        const { rerender } = render(<MfaChallenge {...props} factors={factors.slice(0, 2)} steps={{ total: 2, passed: [] }} />);
        expect(screen.getByText('Step 1 of 2')).toBeInTheDocument();
        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '123456{Enter}');
        expect(inertia.requests).toEqual([{ method: 'post', url: urls.verify, data: { factor_id: 1, code: '123456' } }]);

        // The server answers with the methods still to go.
        rerender(<MfaChallenge {...props} factors={[factors[1]]} defaultFactorId={2} status="factor-verified" steps={{ total: 2, passed: ['totp'] }} />);
        expect(screen.getByText('Step 2 of 2')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Check your email' })).toBeInTheDocument();
        expect(inertia.requests.at(-1)).toEqual({ method: 'post', url: urls.send, data: { factor_id: 2 } });
    });

    it('shows no steps when any one method is enough', () => {
        render(<MfaChallenge {...props} />);

        expect(screen.queryByText(/Step \d of/)).not.toBeInTheDocument();
    });

    it('verifies with the selected factor', async () => {
        render(<MfaChallenge {...props} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '123456{Enter}');

        expect(inertia.requests).toEqual([{ method: 'post', url: urls.verify, data: { factor_id: 1, code: '123456' } }]);
    });

    it('sends a code when an email or SMS method is chosen, and verifies against it', async () => {
        render(<MfaChallenge {...props} />);
        expect(inertia.requests).toEqual([]); // TOTP: nothing to send

        await choose('Email');
        expect(screen.getByRole('heading', { name: 'Check your email' })).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Verification code' })).toHaveFocus();
        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '654321{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.send, data: { factor_id: 2 } },
            { method: 'post', url: urls.verify, data: { factor_id: 2, code: '654321' } },
        ]);
    });

    it('sends a new code on request', async () => {
        render(<MfaChallenge {...props} factors={withSent(2, null)} defaultFactorId={2} />);

        await userEvent.click(screen.getByRole('button', { name: 'Send a new code' }));

        expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);
    });

    it('shows "code sent" and the countdown only for the factor the code went to', async () => {
        const { rerender } = render(<MfaChallenge {...props} />);
        await choose('Email');
        expect(description()).toHaveTextContent("We're sending a code to j***@example.com.");

        // The server redirects back with the status and the cooldown.
        rerender(<MfaChallenge {...props} status="code-sent" retryAfter={120} />);
        expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');
        expect(screen.getByText(/Didn't get it\?/)).toHaveTextContent("Didn't get it? Resend in 2:00");
        expect(screen.getByRole('button', { name: 'Resend in 2:00' })).toBeDisabled();

        // A code already out for SMS (an earlier visit): its own state, no new send.
        rerender(<MfaChallenge {...props} factors={withSent(3, null)} status="code-sent" retryAfter={120} />);
        await choose('Text message');
        expect(description()).toHaveTextContent('Enter the 6-digit code we sent to +*******0100.');
        expect(screen.getByRole('button', { name: 'Send a new code' })).toBeEnabled();
        expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);
    });

    describe('sending on arrival', () => {
        it('sends a code when the page opens on an email or SMS method', () => {
            render(<MfaChallenge {...props} defaultFactorId={2} />);

            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);
        });

        it('sends once under StrictMode', () => {
            render(
                <StrictMode>
                    <MfaChallenge {...props} defaultFactorId={3} />
                </StrictMode>,
            );

            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 3 } }]);
        });

        it('does not send when the page opens on an authenticator app', () => {
            render(<MfaChallenge {...props} />);

            expect(inertia.requests).toEqual([]);
        });

        it('does not send again when a code is already out, and shows the countdown', () => {
            render(<MfaChallenge {...props} factors={withSent(2, 75)} defaultFactorId={2} />);

            expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');
            expect(screen.getByRole('button', { name: 'Resend in 1:15' })).toBeDisabled();
            expect(inertia.requests).toEqual([]);
        });

        it('sends once per method, however often the user switches', async () => {
            render(<MfaChallenge {...props} />);

            for (const name of ['Email', 'Authenticator app', 'Email', 'Text message', 'Email', 'Text message']) {
                await choose(name);
            }

            expect(inertia.requests).toEqual([
                { method: 'post', url: urls.send, data: { factor_id: 2 } },
                { method: 'post', url: urls.send, data: { factor_id: 3 } },
            ]);
        });

        it('does not send again after the server answers', () => {
            const { rerender } = render(<MfaChallenge {...props} defaultFactorId={2} />);
            // The redirect back: fresh props, same page instance.
            rerender(<MfaChallenge {...props} factors={withSent(2, 120)} defaultFactorId={2} status="code-sent" retryAfter={120} />);

            expect(inertia.requests).toHaveLength(1);
        });

        it('does not send by itself after "Send a new code" fails', async () => {
            const { rerender } = render(<MfaChallenge {...props} factors={withSent(2, null)} defaultFactorId={2} />);
            inertia.respondWith({ code: 'We could not send your code. Please try again.' });
            await userEvent.click(screen.getByRole('button', { name: 'Send a new code' }));
            // A failed delivery discards the code, and the old one was replaced: none is out now.
            rerender(<MfaChallenge {...props} defaultFactorId={2} />);

            expect(screen.getByRole('alert')).toHaveTextContent('We could not send your code.');
            expect(inertia.requests).toHaveLength(1);
        });

        it('shows a failed send and does not retry by itself', () => {
            inertia.respondWith({ code: 'We could not send your code. Please try again.' });
            const { rerender } = render(<MfaChallenge {...props} defaultFactorId={2} />);
            rerender(<MfaChallenge {...props} defaultFactorId={2} />);

            expect(screen.getByRole('alert')).toHaveTextContent('We could not send your code.');
            expect(description()).toHaveTextContent("We couldn't send a code to j***@example.com.");
            expect(screen.getByRole('button', { name: 'Send code' })).toBeEnabled();
            expect(inertia.requests).toHaveLength(1);
        });
    });

    it('keeps the countdown after a refresh, from the server\'s send state', () => {
        render(<MfaChallenge {...props} factors={withSent(2, 58)} defaultFactorId={2} />);

        expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');
        expect(screen.getByText(/Didn't get it\?/)).toHaveTextContent("Didn't get it? Resend in 0:58");
        expect(screen.getByRole('button', { name: 'Resend in 0:58' })).toBeDisabled();
    });

    describe('a send refused because a code is already out', () => {
        const cooldown = { code: 'Please wait before requesting another code.' };

        it('shows the countdown, not an error, when coming back to the page (stale props)', () => {
            // Back/forward restores the props from history (no code out yet) and remounts the page.
            inertia.respondWith(cooldown);
            const { rerender } = render(<MfaChallenge {...props} defaultFactorId={2} />);
            // The redirect back: the server's own state, the cooldown flashed, the error under "code".
            rerender(<MfaChallenge {...props} factors={withSent(2, 50)} defaultFactorId={2} retryAfter={50} />);

            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
            expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');
            expect(screen.getByRole('button', { name: 'Resend in 0:50' })).toBeDisabled();
            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);
        });

        it('shows the countdown, not an error, after "Send a new code"', async () => {
            // The page thinks a resend is allowed (e.g. its props are stale).
            const { rerender } = render(<MfaChallenge {...props} factors={withSent(2, null)} defaultFactorId={2} />);
            inertia.respondWith(cooldown);
            await userEvent.click(screen.getByRole('button', { name: 'Send a new code' }));
            rerender(<MfaChallenge {...props} factors={withSent(2, 40)} defaultFactorId={2} retryAfter={40} />);

            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Resend in 0:40' })).toBeDisabled();
            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);
        });

        it('still shows other refusals, e.g. the hourly limit with a code out', async () => {
            const { rerender } = render(<MfaChallenge {...props} factors={withSent(2, null)} defaultFactorId={2} />);
            inertia.respondWith({ code: 'Too many attempts. Please try again later.' });
            await userEvent.click(screen.getByRole('button', { name: 'Send a new code' }));
            // No cooldown in the factor's state: the limit, not the code out, refused it.
            rerender(<MfaChallenge {...props} factors={withSent(2, null)} defaultFactorId={2} retryAfter={2400} />);

            expect(screen.getByRole('alert')).toHaveTextContent('Too many attempts.');
            expect(screen.getByRole('button', { name: 'Resend in 40:00' })).toBeDisabled();
        });
    });

    describe('countdowns while the page is open', () => {
        // Real time still moves, so userEvent works; advanceTimersByTime() jumps ahead.
        beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }));
        const wait = (seconds: number) => act(() => vi.advanceTimersByTime(seconds * 1000));

        it("counts each method's cooldown from page load, not from when it is shown", async () => {
            const sent = factors.map((f) => (f.id === 2 ? { ...f, code_sent: true, retry_after: null } : f.id === 3 ? { ...f, code_sent: true, retry_after: 60 } : f));
            render(<MfaChallenge {...props} factors={sent} defaultFactorId={2} />);

            wait(50);
            await choose('Text message');

            expect(screen.getByRole('button', { name: 'Resend in 0:10' })).toBeDisabled();
            expect(inertia.requests).toEqual([]);
        });

        it('counts a send from when it answered, across switching methods', async () => {
            const { rerender } = render(<MfaChallenge {...props} defaultFactorId={2} />);
            rerender(<MfaChallenge {...props} factors={withSent(2, 120)} defaultFactorId={2} status="code-sent" retryAfter={120} />);

            await choose('Authenticator app');
            wait(30);
            await choose('Email');

            expect(screen.getByRole('button', { name: 'Resend in 1:30' })).toBeDisabled();
            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);
        });

        it('treats a code as gone once it expires, and sends a new one', () => {
            const expiring = (retryAfter: number) => factors.map((f) => (f.id === 2 ? { ...f, code_sent: true, retry_after: retryAfter, expires_in: 300 } : f));
            const { rerender } = render(<MfaChallenge {...props} factors={expiring(30)} defaultFactorId={2} />);
            expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');

            wait(299);
            expect(inertia.requests).toEqual([]);
            wait(1);
            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);

            rerender(<MfaChallenge {...props} factors={expiring(120)} defaultFactorId={2} status="code-sent" retryAfter={120} />);
            expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');
            expect(screen.getByRole('button', { name: 'Resend in 2:00' })).toBeDisabled();
        });

        it('sends on expiry at most once per method per visit', () => {
            const expiring = (retryAfter: number) => factors.map((f) => (f.id === 2 ? { ...f, code_sent: true, retry_after: retryAfter, expires_in: 300 } : f));
            const { rerender } = render(<MfaChallenge {...props} defaultFactorId={2} />);
            rerender(<MfaChallenge {...props} factors={expiring(120)} defaultFactorId={2} status="code-sent" retryAfter={120} />);

            wait(300);

            expect(description()).toHaveTextContent('The code we sent to j***@example.com has expired.');
            expect(screen.getByRole('button', { name: 'Send code' })).toBeEnabled();
            expect(inertia.requests).toHaveLength(1);
        });
    });

    describe('a code used moments ago (the cooldown spans logins)', () => {
        beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }));
        const wait = (seconds: number) => act(() => vi.advanceTimersByTime(seconds * 1000));
        /** The server's state after a verified code: none out, the next send waits. */
        const waiting = (seconds: number) => factors.map((f) => (f.id === 2 ? { ...f, code_sent: false, retry_after: seconds, expires_in: null } : f));

        it('does not send while the wait runs, then sends by itself once', () => {
            const { rerender } = render(<MfaChallenge {...props} factors={waiting(120)} defaultFactorId={2} />);

            expect(description()).toHaveTextContent('You recently used a code sent to j***@example.com.');
            expect(screen.getByRole('button', { name: 'You can get a new code in 2:00' })).toBeDisabled();
            expect(screen.queryByRole('alert')).not.toBeInTheDocument();

            wait(119);
            expect(inertia.requests).toEqual([]);
            wait(1);
            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);

            // The redirect back: the code is out.
            rerender(<MfaChallenge {...props} factors={withSent(2, 240)} defaultFactorId={2} status="code-sent" retryAfter={240} />);
            expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');
            expect(screen.getByRole('button', { name: 'Resend in 4:00' })).toBeDisabled();
            wait(600);
            expect(inertia.requests).toHaveLength(1);
        });

        it('sends at most once when the wait ends, even if that send fails', () => {
            inertia.respondWith({ code: 'We could not send your code. Please try again.' });
            const { rerender } = render(<MfaChallenge {...props} factors={waiting(30)} defaultFactorId={2} />);
            wait(30);
            rerender(<MfaChallenge {...props} defaultFactorId={2} />);

            wait(600);
            expect(screen.getByRole('alert')).toHaveTextContent('We could not send your code.');
            expect(screen.getByRole('button', { name: 'Send code' })).toBeEnabled();
            expect(inertia.requests).toHaveLength(1);
        });

        it('waits too when the method is picked from the list', async () => {
            render(<MfaChallenge {...props} factors={waiting(60)} />);

            await choose('Email');
            expect(description()).toHaveTextContent('You recently used a code sent to j***@example.com.');
            expect(inertia.requests).toEqual([]);

            wait(60);
            expect(inertia.requests).toEqual([{ method: 'post', url: urls.send, data: { factor_id: 2 } }]);
        });

        it('shows a send refused by that wait as the countdown, not an error (stale props)', () => {
            inertia.respondWith({ code: 'Please wait before requesting another code.' });
            const { rerender } = render(<MfaChallenge {...props} defaultFactorId={2} />);
            rerender(<MfaChallenge {...props} factors={waiting(50)} defaultFactorId={2} retryAfter={50} />);

            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
            expect(description()).toHaveTextContent('You recently used a code sent to j***@example.com.');
            expect(screen.getByRole('button', { name: 'You can get a new code in 0:50' })).toBeDisabled();
        });

        it('still shows the daily cap, with the wait in hours', () => {
            inertia.respondWith({ code: "You've had too many codes today. Try again in 5 hours, or use an authenticator app." });
            const { rerender } = render(<MfaChallenge {...props} defaultFactorId={2} />);
            rerender(<MfaChallenge {...props} defaultFactorId={2} retryAfter={5 * 3600} />);

            expect(screen.getByRole('alert')).toHaveTextContent("You've had too many codes today.");
            expect(description()).toHaveTextContent("We couldn't send a code to j***@example.com.");
            expect(screen.getByRole('button', { name: 'You can get a new code in 5:00:00' })).toBeDisabled();
            wait(5 * 3600);
            expect(inertia.requests).toHaveLength(1);
        });
    });

    it('switches to a recovery code and back to the list, and signs out', async () => {
        render(<MfaChallenge {...props} />);

        await choose('Recovery code');
        expect(screen.getByRole('heading', { name: 'Use a recovery code' })).toBeInTheDocument();
        await userEvent.type(screen.getByRole('textbox', { name: 'Recovery code' }), 'abcde-12345{Enter}');
        await userEvent.click(screen.getByRole('button', { name: 'Sign out' }));

        await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));
        expect(screen.getByRole('heading', { name: 'Choose how to verify' })).toHaveFocus();
        await userEvent.click(screen.getByRole('button', { name: /^Authenticator app/ }));

        expect(screen.getByRole('textbox', { name: 'Verification code' })).toHaveFocus();
        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.recover, data: { code: 'abcde-12345' } },
            { method: 'post', url: urls.logout, data: {} },
        ]);
    });

    it('hides recovery codes and sign-out when unavailable', async () => {
        render(<MfaChallenge {...props} hasRecoveryCodes={false} urls={{ ...urls, logout: null }} />);

        expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));
        expect(screen.queryByRole('button', { name: /^Recovery code/ })).not.toBeInTheDocument();
    });

    it('offers no other way with one method and no recovery codes', () => {
        render(<MfaChallenge {...props} factors={[factors[0]]} hasRecoveryCodes={false} />);

        expect(screen.queryByRole('button', { name: 'Try another way' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sign out' })).toBeInTheDocument();
    });

    it('takes the code length from the factor', async () => {
        render(<MfaChallenge {...props} factors={[{ ...factors[1], code_sent: true, code_length: 8 }]} defaultFactorId={2} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '12345678{Enter}');

        expect(inertia.requests).toEqual([{ method: 'post', url: urls.verify, data: { factor_id: 2, code: '12345678' } }]);
    });

    describe("don't ask again on this browser", () => {
        const checkbox = () => screen.queryByRole('checkbox', { name: /Don't ask again on this browser/ });

        it('is not offered when the server says null', () => {
            render(<MfaChallenge {...props} />);

            expect(checkbox()).not.toBeInTheDocument();
        });

        it('sends remember: true only when ticked', async () => {
            render(<MfaChallenge {...props} trustBrowser={{ days: 30 }} />);
            expect(screen.getByRole('checkbox', { name: "Don't ask again on this browser for 30 days" })).not.toBeChecked();

            await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '111111{Enter}');
            await userEvent.click(checkbox()!);
            await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

            expect(inertia.requests).toEqual([
                { method: 'post', url: urls.verify, data: { factor_id: 1, code: '111111' } },
                { method: 'post', url: urls.verify, data: { factor_id: 1, code: '111111', remember: true } },
            ]);
        });

        it('is never sent with a recovery code', async () => {
            render(<MfaChallenge {...props} trustBrowser={{ days: 30 }} />);
            await userEvent.click(checkbox()!);

            await choose('Recovery code');
            expect(checkbox()).not.toBeInTheDocument();
            await userEvent.type(screen.getByRole('textbox', { name: 'Recovery code' }), 'abcde-12345{Enter}');

            expect(inertia.requests).toEqual([{ method: 'post', url: urls.recover, data: { code: 'abcde-12345' } }]);
        });
    });

    describe('verifying early (renew)', () => {
        const checkbox = () => screen.queryByRole('checkbox', { name: /Don't ask again on this browser/ });

        it('starts with the box ticked and keeps the browser trusted', async () => {
            render(<MfaChallenge {...props} renew trustBrowser={{ days: 30 }} />);

            expect(screen.getByText('Verify now so this browser keeps skipping the code for another 30 days.')).toBeInTheDocument();
            expect(checkbox()).toBeChecked();
            await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '123456{Enter}');

            expect(inertia.requests).toEqual([{ method: 'post', url: urls.verify, data: { factor_id: 1, code: '123456', remember: true } }]);
        });

        it('can still be unticked', async () => {
            render(<MfaChallenge {...props} renew trustBrowser={{ days: 30 }} />);

            await userEvent.click(checkbox()!);
            await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '123456{Enter}');

            expect(inertia.requests).toEqual([{ method: 'post', url: urls.verify, data: { factor_id: 1, code: '123456' } }]);
        });

        it('goes back with "Not now" instead of signing out', async () => {
            const length = vi.spyOn(window.history, 'length', 'get').mockReturnValue(3);
            const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
            render(<MfaChallenge {...props} renew trustBrowser={{ days: 30 }} />);

            expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();
            await userEvent.click(screen.getByRole('button', { name: 'Not now' }));

            expect(back).toHaveBeenCalledOnce();
            expect(inertia.requests).toEqual([]);
            back.mockRestore();
            length.mockRestore();
        });

        it('goes to the start page with "Not now" when there is no page to go back to (a new tab)', async () => {
            const length = vi.spyOn(window.history, 'length', 'get').mockReturnValue(1);
            const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
            const assign = vi.fn();
            vi.stubGlobal('location', { ...window.location, assign });
            render(<MfaChallenge {...props} renew trustBrowser={{ days: 30 }} />);

            await userEvent.click(screen.getByRole('button', { name: 'Not now' }));

            expect(assign).toHaveBeenCalledWith('/');
            expect(back).not.toHaveBeenCalled();
            vi.unstubAllGlobals();
            back.mockRestore();
            length.mockRestore();
        });

        it('is off by default: the box starts unticked, with Sign out and no Not now', () => {
            render(<MfaChallenge {...props} trustBrowser={{ days: 30 }} />);

            expect(checkbox()).not.toBeChecked();
            expect(screen.getByRole('button', { name: 'Sign out' })).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: 'Not now' })).not.toBeInTheDocument();
            expect(screen.queryByText(/keeps skipping the code/)).not.toBeInTheDocument();
        });
    });
});
