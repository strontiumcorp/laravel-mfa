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

    it('keeps only the first code when several lines are pasted', async () => {
        const onSubmit = vi.fn();
        render(<MfaRecoveryCodeForm onSubmit={onSubmit} />);
        const input = screen.getByRole('textbox', { name: 'Recovery code' });

        await userEvent.click(input);
        await userEvent.paste('bxztk-e78s3\nvfw8q-zmmh8\nv7wsa-k2m9p\n');

        expect(input).toHaveValue('bxztk-e78s3');
        expect((input as HTMLInputElement).selectionStart).toBe('bxztk-e78s3'.length);
        await userEvent.click(screen.getByRole('button', { name: 'Verify' }));
        expect(onSubmit).toHaveBeenCalledWith('bxztk-e78s3');
    });

    it('keeps only the first code when codes separated by spaces are pasted', async () => {
        render(<MfaRecoveryCodeForm onSubmit={() => {}} />);
        const input = screen.getByRole('textbox', { name: 'Recovery code' });

        await userEvent.click(input);
        await userEvent.paste('bxztk-e78s3 vfw8q-zmmh8 v7ws');

        expect(input).toHaveValue('bxztk-e78s3');
    });

    it('keeps the first code of a numbered, comma-separated list', async () => {
        render(<MfaRecoveryCodeForm onSubmit={() => {}} />);
        const input = screen.getByRole('textbox', { name: 'Recovery code' });

        await userEvent.click(input);
        await userEvent.paste('1. bxztk-e78s3, 2. vfw8q-zmmh8');

        expect(input).toHaveValue('bxztk-e78s3');
    });

    it('leaves one code with spaces or dashes as typed', async () => {
        render(<MfaRecoveryCodeForm onSubmit={() => {}} />);
        const input = screen.getByRole('textbox', { name: 'Recovery code' });

        await userEvent.click(input);
        await userEvent.paste('bxztk e78s3');
        expect(input).toHaveValue('bxztk e78s3');

        await userEvent.clear(input);
        await userEvent.type(input, 'BXZTK-E78S3');
        expect(input).toHaveValue('BXZTK-E78S3');
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

    it('says that one line of the saved list is one code, with an example', () => {
        render(<MfaRecoveryCodeForm onSubmit={() => {}} />);

        expect(screen.getByRole('textbox', { name: 'Recovery code' })).toHaveAccessibleDescription(
            'Enter one code from your saved list, like k7m2p-x1q0t. Each code works once.',
        );
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
