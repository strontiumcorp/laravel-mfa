import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaChallenge from '../../stubs/inertia-react/pages/challenge';
import { inertia } from './inertia-mock';

vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);

const factors = [
    { id: 1, type: 'totp' as const, type_label: 'Authenticator app', label: null, destination: null },
    { id: 2, type: 'email' as const, type_label: 'Email', label: null, destination: 'j***@example.com' },
    { id: 3, type: 'sms' as const, type_label: 'SMS', label: null, destination: '+*******0100' },
];
const urls = { send: '/mfa/challenge/send', verify: '/mfa/challenge/verify', recover: '/mfa/challenge/recover', logout: '/logout' };
const props = { factors, defaultFactorId: 1, hasRecoveryCodes: true, status: null, retryAfter: null, urls };

beforeEach(() => inertia.reset());

describe('challenge page', () => {
    it('verifies with the selected factor', async () => {
        render(<MfaChallenge {...props} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '123456{Enter}');

        expect(inertia.requests).toEqual([{ method: 'post', url: urls.verify, data: { factor_id: 1, code: '123456' } }]);
    });

    it('sends a code to the chosen factor and verifies against it', async () => {
        render(<MfaChallenge {...props} />);
        expect(screen.queryByRole('button', { name: 'Send code' })).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Email' }));
        await userEvent.click(screen.getByRole('button', { name: 'Send code' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '654321{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.send, data: { factor_id: 2 } },
            { method: 'post', url: urls.verify, data: { factor_id: 2, code: '654321' } },
        ]);
    });

    it('shows "code sent" and the countdown only for the factor the code went to', async () => {
        const { rerender } = render(<MfaChallenge {...props} />);
        await userEvent.click(screen.getByRole('button', { name: 'Email' }));
        await userEvent.click(screen.getByRole('button', { name: 'Send code' }));

        // The server redirects back with the status and the cooldown.
        rerender(<MfaChallenge {...props} status="code-sent" retryAfter={120} />);
        expect(screen.getByRole('status')).toHaveTextContent('Code sent.');
        expect(screen.getByRole('button', { name: 'Resend in 2:00' })).toBeDisabled();

        await userEvent.click(screen.getByRole('button', { name: 'SMS' }));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Send code' })).toBeEnabled();
    });

    it('switches to recovery codes and back, and signs out', async () => {
        render(<MfaChallenge {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Use a recovery code' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Recovery code' }), 'abcde-12345{Enter}');
        await userEvent.click(screen.getByRole('button', { name: 'Sign out' }));
        await userEvent.click(screen.getByRole('button', { name: 'Use a verification code' }));

        expect(screen.getByRole('textbox', { name: 'Verification code' })).toBeInTheDocument();
        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.recover, data: { code: 'abcde-12345' } },
            { method: 'post', url: urls.logout, data: {} },
        ]);
    });

    it('hides recovery codes and sign-out when unavailable', () => {
        render(<MfaChallenge {...props} hasRecoveryCodes={false} urls={{ ...urls, logout: null }} />);

        expect(screen.queryByRole('button', { name: 'Use a recovery code' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();
    });
});
