import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaSendCodeButton from '../../stubs/inertia-react/components/send-code-button';

/** Each tick schedules the next after a render, so advance a second at a time. */
const tick = (seconds: number) => {
    for (let i = 0; i < seconds; i++) act(() => vi.advanceTimersByTime(1000));
};

describe('MfaSendCodeButton', () => {
    it('sends a code', async () => {
        const onSend = vi.fn();
        render(<MfaSendCodeButton onSend={onSend} />);

        await userEvent.click(screen.getByRole('button', { name: 'Send code' }));

        expect(onSend).toHaveBeenCalledOnce();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('confirms a sent code and offers a new one', () => {
        render(<MfaSendCodeButton onSend={() => {}} sent />);

        expect(screen.getByRole('status')).toHaveTextContent('Code sent.');
        expect(screen.getByRole('button', { name: 'Send a new code' })).toBeEnabled();
    });

    it('is disabled while sending', () => {
        render(<MfaSendCodeButton onSend={() => {}} processing />);

        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('shows the error', () => {
        render(<MfaSendCodeButton onSend={() => {}} error="Please wait before requesting another code." />);

        expect(screen.getByRole('alert')).toHaveTextContent('Please wait before requesting another code.');
    });

    it('counts down the cooldown, then enables the button', () => {
        vi.useFakeTimers();
        render(<MfaSendCodeButton onSend={() => {}} retryAfter={62} sent />);

        expect(screen.getByRole('button')).toHaveTextContent('Resend in 1:02');
        expect(screen.getByRole('button')).toBeDisabled();

        tick(3);
        expect(screen.getByRole('button')).toHaveTextContent('Resend in 0:59');

        tick(59);
        expect(screen.getByRole('button', { name: 'Send a new code' })).toBeEnabled();
    });

    it('restarts the countdown when the server sends a new wait', () => {
        vi.useFakeTimers();
        const { rerender } = render(<MfaSendCodeButton onSend={() => {}} retryAfter={5} />);
        tick(5);
        expect(screen.getByRole('button', { name: 'Send code' })).toBeEnabled();

        rerender(<MfaSendCodeButton onSend={() => {}} retryAfter={120} />);

        expect(screen.getByRole('button')).toHaveTextContent('Resend in 2:00');
        expect(screen.getByRole('button')).toBeDisabled();
    });
});
