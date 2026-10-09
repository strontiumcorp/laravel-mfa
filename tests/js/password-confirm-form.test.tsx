import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaPasswordConfirmForm from '../../stubs/inertia-react/components/password-confirm-form';

describe('MfaPasswordConfirmForm', () => {
    it('asks for the password and submits it as typed', async () => {
        const onConfirm = vi.fn();
        render(<MfaPasswordConfirmForm onConfirm={onConfirm} />);

        expect(screen.getByRole('heading', { name: 'Confirm your password' })).toBeInTheDocument();
        const input = screen.getByLabelText('Password');
        expect(input).toHaveAttribute('type', 'password');
        expect(input).toHaveAttribute('autocomplete', 'current-password');
        expect(input).toHaveFocus();

        await userEvent.type(input, ' pass word ');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(onConfirm).toHaveBeenCalledWith(' pass word ');
    });

    it('needs a password, and is disabled while confirming', async () => {
        const { rerender } = render(<MfaPasswordConfirmForm onConfirm={() => {}} />);
        expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();

        await userEvent.type(screen.getByLabelText('Password'), 'secret');
        expect(screen.getByRole('button', { name: 'Confirm' })).toBeEnabled();

        rerender(<MfaPasswordConfirmForm onConfirm={() => {}} onCancel={() => {}} processing />);
        expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });

    it('shows the error and clears the password after a failed attempt', async () => {
        const { rerender } = render(<MfaPasswordConfirmForm onConfirm={() => {}} />);
        await userEvent.type(screen.getByLabelText('Password'), 'wrong');

        rerender(<MfaPasswordConfirmForm onConfirm={() => {}} processing />);
        rerender(<MfaPasswordConfirmForm onConfirm={() => {}} error="The provided password is incorrect." />);

        expect(screen.getByRole('alert')).toHaveTextContent('The provided password is incorrect.');
        expect(screen.getByLabelText('Password')).toHaveValue('');
    });

    it('cancels only when given a callback', async () => {
        const onCancel = vi.fn();
        const { rerender } = render(<MfaPasswordConfirmForm onConfirm={() => {}} />);
        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();

        rerender(<MfaPasswordConfirmForm onConfirm={() => {}} onCancel={onCancel} />);
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onCancel).toHaveBeenCalledOnce();
    });

    it('accepts extra classes', () => {
        render(<MfaPasswordConfirmForm onConfirm={() => {}} className="mt-4" />);

        expect(screen.getByRole('region', { name: 'Confirm your password' })).toHaveClass('mt-4');
    });

    it('counts down while attempts are locked, then allows the next one', () => {
        vi.useFakeTimers();
        render(<MfaPasswordConfirmForm onConfirm={() => {}} retryAfter={2} error="Too many attempts. Please try again later." />);
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });

        expect(screen.getByRole('button', { name: 'Try again in 0:02' })).toBeDisabled();
        // Each tick schedules the next after a render, so advance a second at a time.
        act(() => vi.advanceTimersByTime(1000));
        act(() => vi.advanceTimersByTime(1000));
        expect(screen.getByRole('button', { name: 'Confirm' })).toBeEnabled();
    });

    it('shows a daily lockout in hours', () => {
        render(<MfaPasswordConfirmForm onConfirm={() => {}} retryAfter={86400} />);

        expect(screen.getByRole('button', { name: 'Try again in 24 h' })).toBeDisabled();
    });
});
