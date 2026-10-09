import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaDestinationSetup, { type MfaDestinationSetupProps } from '../../stubs/inertia-react/components/destination-setup';

const tick = (seconds: number) => {
    for (let i = 0; i < seconds; i++) act(() => vi.advanceTimersByTime(1000));
};

const base: MfaDestinationSetupProps = { label: 'Email', destination: 'j***@example.com', onConfirm: () => {}, onResend: () => {} };

describe('MfaDestinationSetup', () => {
    it('says where the code went', () => {
        render(<MfaDestinationSetup {...base} />);

        expect(screen.getByRole('heading')).toHaveTextContent('Finish setting up email');
        expect(screen.getByText(/We sent a code to j\*\*\*@example\.com\./)).toBeInTheDocument();
    });

    it('confirms with the digits entered', async () => {
        const onConfirm = vi.fn();
        render(<MfaDestinationSetup {...base} onConfirm={onConfirm} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '9876x54{Enter}');

        expect(onConfirm).toHaveBeenCalledWith('987654');
    });

    it('resends', async () => {
        const onResend = vi.fn();
        render(<MfaDestinationSetup {...base} onResend={onResend} />);

        await userEvent.click(screen.getByRole('button', { name: 'Resend' }));

        expect(onResend).toHaveBeenCalledOnce();
    });

    it('confirms a resent code', () => {
        render(<MfaDestinationSetup {...base} sent />);

        expect(screen.getByRole('status')).toHaveTextContent('Sent!');
    });

    it('counts down before allowing a resend', () => {
        vi.useFakeTimers();
        render(<MfaDestinationSetup {...base} retryAfter={3} />);
        const resend = screen.getByRole('button', { name: 'Resend in 0:03' });
        expect(resend).toBeDisabled();

        tick(2);
        expect(resend).toHaveTextContent('Resend in 0:01');
        tick(1);
        expect(resend).toHaveTextContent('Resend');
        expect(resend).toBeEnabled();
    });

    it('disables resend while resending, and confirm while confirming', async () => {
        render(<MfaDestinationSetup {...base} resending processing />);
        await userEvent.type(screen.getByRole('textbox'), '123456');

        expect(screen.getByRole('button', { name: 'Resend' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
    });

    it('shows the error and clears the input after a failed attempt', async () => {
        const { rerender } = render(<MfaDestinationSetup {...base} />);
        await userEvent.type(screen.getByRole('textbox'), '123456');

        rerender(<MfaDestinationSetup {...base} processing />);
        rerender(<MfaDestinationSetup {...base} error="This code has expired." />);

        expect(screen.getByRole('alert')).toHaveTextContent('This code has expired.');
        expect(screen.getByRole('textbox')).toHaveValue('');
    });

    it('drops its own box and heading inside a card (framed={false})', () => {
        render(<MfaDestinationSetup label="Email" destination="j***@example.com" onConfirm={() => {}} onResend={() => {}} framed={false} />);

        expect(screen.queryByRole('heading')).not.toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Finish setting up email' })).not.toHaveClass('border');
    });
});
