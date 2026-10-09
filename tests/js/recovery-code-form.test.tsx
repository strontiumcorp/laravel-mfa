import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaRecoveryCodeForm from '../../stubs/inertia-react/components/recovery-code-form';

describe('MfaRecoveryCodeForm', () => {
    it('submits the trimmed code', async () => {
        const onSubmit = vi.fn();
        render(<MfaRecoveryCodeForm onSubmit={onSubmit} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Recovery code' }), '  abcde-12345 ');
        await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

        expect(onSubmit).toHaveBeenCalledWith('abcde-12345');
    });

    it('needs a code', async () => {
        render(<MfaRecoveryCodeForm onSubmit={() => {}} />);
        const verify = screen.getByRole('button', { name: 'Verify' });

        expect(verify).toBeDisabled();
        await userEvent.type(screen.getByRole('textbox', { name: 'Recovery code' }), '   ');
        expect(verify).toBeDisabled();
    });

    it('is disabled while verifying', async () => {
        render(<MfaRecoveryCodeForm onSubmit={() => {}} processing />);
        await userEvent.type(screen.getByRole('textbox', { name: 'Recovery code' }), 'abcde-12345');

        expect(screen.getByRole('button', { name: 'Verify' })).toBeDisabled();
    });

    it('shows the error and keeps the code for correcting', async () => {
        const { rerender } = render(<MfaRecoveryCodeForm onSubmit={() => {}} />);
        await userEvent.type(screen.getByRole('textbox', { name: 'Recovery code' }), 'abcde-1234');

        rerender(<MfaRecoveryCodeForm onSubmit={() => {}} error="Invalid recovery code." />);

        expect(screen.getByRole('alert')).toHaveTextContent('Invalid recovery code.');
        expect(screen.getByRole('textbox', { name: 'Recovery code' })).toHaveValue('abcde-1234');
    });

    it('is its own titled step', () => {
        render(<MfaRecoveryCodeForm onSubmit={() => {}} />);

        expect(screen.getByRole('heading', { name: 'Use a recovery code' })).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Recovery code' })).toHaveFocus();
    });

    it('goes back to the other ways in, when given the callback', async () => {
        const onTryAnotherWay = vi.fn();
        render(<MfaRecoveryCodeForm onSubmit={() => {}} onTryAnotherWay={onTryAnotherWay} onUseVerificationCode={() => {}} />);

        await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));

        expect(onTryAnotherWay).toHaveBeenCalledOnce();
        expect(screen.queryByRole('button', { name: 'Use a verification code' })).not.toBeInTheDocument();
    });

    it('switches back and signs out only when given callbacks', async () => {
        const onUseVerificationCode = vi.fn();
        const onSignOut = vi.fn();
        const { rerender } = render(<MfaRecoveryCodeForm onSubmit={() => {}} />);
        expect(screen.queryByRole('button', { name: 'Use a verification code' })).not.toBeInTheDocument();

        rerender(<MfaRecoveryCodeForm onSubmit={() => {}} onUseVerificationCode={onUseVerificationCode} onSignOut={onSignOut} />);
        await userEvent.click(screen.getByRole('button', { name: 'Use a verification code' }));
        await userEvent.click(screen.getByRole('button', { name: 'Sign out' }));

        expect(onUseVerificationCode).toHaveBeenCalledOnce();
        expect(onSignOut).toHaveBeenCalledOnce();
    });
});
