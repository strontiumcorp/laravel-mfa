import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaChallengeForm, { type MfaChallengeFactor } from '../../stubs/inertia-react/components/challenge-form';

const totp: MfaChallengeFactor = { id: 1, type: 'totp', type_label: 'Authenticator app', label: null, destination: null };
const email: MfaChallengeFactor = { id: 2, type: 'email', type_label: 'Email', label: 'Work email', destination: 'j***@example.com' };

const setup = (props: Partial<Parameters<typeof MfaChallengeForm>[0]> = {}) => {
    const callbacks = { onSelectFactor: vi.fn(), onSubmit: vi.fn() };
    const view = render(<MfaChallengeForm factors={[totp, email]} selectedFactorId={1} {...callbacks} {...props} />);

    return { ...callbacks, ...view };
};

describe('MfaChallengeForm', () => {
    it('asks for the authenticator code', () => {
        setup();

        expect(screen.getByText('Enter the 6-digit code from your authenticator app.')).toBeInTheDocument();
    });

    it('says where a delivered code goes', () => {
        setup({ selectedFactorId: 2 });

        expect(screen.getByText("We'll send a code to j***@example.com.")).toBeInTheDocument();
    });

    it('says when a code is already on its way', () => {
        setup({ factors: [totp, { ...email, code_sent: true }], selectedFactorId: 2 });

        expect(screen.getByText('We sent a code to j***@example.com.')).toBeInTheDocument();
    });

    it('lets the user pick another factor', async () => {
        const { onSelectFactor } = setup();

        expect(screen.getByRole('button', { name: 'Authenticator app' })).toHaveAttribute('aria-pressed', 'true');
        await userEvent.click(screen.getByRole('button', { name: 'Work email' }));

        expect(onSelectFactor).toHaveBeenCalledWith(2);
    });

    it('hides the picker with a single factor', () => {
        setup({ factors: [totp] });

        expect(screen.queryByRole('group', { name: 'Verification method' })).not.toBeInTheDocument();
    });

    it('submits the digits only', async () => {
        const { onSubmit } = setup();
        const input = screen.getByRole('textbox', { name: 'Verification code' });

        await userEvent.type(input, '12a 34-56');
        expect(input).toHaveValue('123456');
        await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

        expect(onSubmit).toHaveBeenCalledWith('123456');
    });

    it('needs at least 4 digits and caps at 10', async () => {
        setup();
        const input = screen.getByRole('textbox', { name: 'Verification code' });
        const verify = screen.getByRole('button', { name: 'Verify' });

        await userEvent.type(input, '123');
        expect(verify).toBeDisabled();
        await userEvent.type(input, '456789012345');
        expect(input).toHaveValue('1234567890');
        expect(verify).toBeEnabled();
    });

    it('submits with Enter', async () => {
        const { onSubmit } = setup();

        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '654321{Enter}');

        expect(onSubmit).toHaveBeenCalledWith('654321');
    });

    it('is disabled while verifying', async () => {
        setup({ processing: true });
        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '123456');

        expect(screen.getByRole('button', { name: 'Verify' })).toBeDisabled();
    });

    it('shows the error and keeps the code, selected, after a failed attempt', async () => {
        const { rerender, onSubmit, onSelectFactor } = setup();
        const input = screen.getByRole('textbox', { name: 'Verification code' });
        await userEvent.type(input, '123456');

        rerender(<MfaChallengeForm factors={[totp, email]} selectedFactorId={1} onSubmit={onSubmit} onSelectFactor={onSelectFactor} processing />);
        rerender(<MfaChallengeForm factors={[totp, email]} selectedFactorId={1} onSubmit={onSubmit} onSelectFactor={onSelectFactor} error="Invalid code." />);

        expect(screen.getByRole('alert')).toHaveTextContent('Invalid code.');
        expect(input).toHaveValue('123456');
        // Kept so the user sees what they typed, and selected so typing replaces it.
        expect(input).toHaveFocus();
        expect([(input as HTMLInputElement).selectionStart, (input as HTMLInputElement).selectionEnd]).toEqual([0, 6]);
    });

    it('selects the code again after a second failure with the same message', async () => {
        const { rerender, onSubmit, onSelectFactor } = setup({ error: 'Invalid code.' });
        const input = screen.getByRole('textbox', { name: 'Verification code' });
        await userEvent.type(input, '123456');

        rerender(<MfaChallengeForm factors={[totp, email]} selectedFactorId={1} onSubmit={onSubmit} onSelectFactor={onSelectFactor} processing error="Invalid code." />);
        rerender(<MfaChallengeForm factors={[totp, email]} selectedFactorId={1} onSubmit={onSubmit} onSelectFactor={onSelectFactor} error="Invalid code." />);

        expect(input).toHaveValue('123456');
        // Kept so the user sees what they typed, and selected so typing replaces it.
        expect(input).toHaveFocus();
        expect([(input as HTMLInputElement).selectionStart, (input as HTMLInputElement).selectionEnd]).toEqual([0, 6]);
    });

    it('keeps the input when there is an old error but no new attempt', async () => {
        setup({ error: 'Invalid code.' });
        const input = screen.getByRole('textbox', { name: 'Verification code' });

        await userEvent.type(input, '123456');

        expect(input).toHaveValue('123456');
    });

    it('clears the input when the factor changes', async () => {
        const { rerender, onSubmit, onSelectFactor } = setup();
        const input = screen.getByRole('textbox', { name: 'Verification code' });
        await userEvent.type(input, '123456');

        rerender(<MfaChallengeForm factors={[totp, email]} selectedFactorId={2} onSubmit={onSubmit} onSelectFactor={onSelectFactor} />);

        expect(input).toHaveValue('');
    });

    it('renders children above the code input', () => {
        setup({ children: <button type="button">Send code</button> });

        const send = screen.getByRole('button', { name: 'Send code' });
        const input = screen.getByRole('textbox', { name: 'Verification code' });
        expect(send.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('offers recovery codes and sign-out only when given callbacks', async () => {
        const onUseRecoveryCode = vi.fn();
        const onSignOut = vi.fn();
        const { rerender, onSubmit, onSelectFactor } = setup();
        expect(screen.queryByRole('button', { name: 'Use a recovery code' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();

        rerender(
            <MfaChallengeForm
                factors={[totp]}
                selectedFactorId={1}
                onSubmit={onSubmit}
                onSelectFactor={onSelectFactor}
                onUseRecoveryCode={onUseRecoveryCode}
                onSignOut={onSignOut}
            />,
        );
        await userEvent.click(screen.getByRole('button', { name: 'Use a recovery code' }));
        await userEvent.click(screen.getByRole('button', { name: 'Sign out' }));

        expect(onUseRecoveryCode).toHaveBeenCalledOnce();
        expect(onSignOut).toHaveBeenCalledOnce();
        expect(onSubmit).not.toHaveBeenCalled();
    });
});
