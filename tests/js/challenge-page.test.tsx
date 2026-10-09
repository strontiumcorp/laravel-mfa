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
const props = { factors, defaultFactorId: 1, hasRecoveryCodes: true, status: null, retryAfter: null, urls };
/** "Try another way", then the method whose row starts with this name. */
const choose = async (name: string) => {
    await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));
    await userEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }));
};
/** The line under the title. */
const description = () => document.querySelector('[aria-live="polite"]');

beforeEach(() => inertia.reset());

describe('challenge page', () => {
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
});
