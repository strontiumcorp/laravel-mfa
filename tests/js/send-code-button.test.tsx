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

    it('offers a new code once one is out', () => {
        const { container } = render(<MfaSendCodeButton onSend={() => {}} sent />);

        expect(container).toHaveTextContent("Didn't get it? Send a new code");
        expect(screen.getByRole('button', { name: 'Send a new code' })).toBeEnabled();
    });

    it('is disabled while sending', () => {
        render(<MfaSendCodeButton onSend={() => {}} processing sent />);

        expect(screen.getByRole('button', { name: 'Sending…' })).toBeDisabled();
    });

    it('shows the error', () => {
        render(<MfaSendCodeButton onSend={() => {}} error="Please wait before requesting another code." />);

        expect(screen.getByRole('alert')).toHaveTextContent('Please wait before requesting another code.');
    });

    it('counts down the cooldown, then enables the button', () => {
        vi.useFakeTimers();
        const { container } = render(<MfaSendCodeButton onSend={() => {}} retryAfter={62} sent />);

        expect(container).toHaveTextContent("Didn't get it? Resend in 1:02");
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

        expect(screen.getByRole('button')).toHaveTextContent('You can get a new code in 2:00');
        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('says when a new code can be sent while none is out', () => {
        vi.useFakeTimers();
        const { container } = render(<MfaSendCodeButton onSend={() => {}} retryAfter={90} />);

        expect(container).toHaveTextContent(/^You can get a new code in 1:30$/);
        expect(screen.getByRole('button', { name: 'You can get a new code in 1:30' })).toBeDisabled();

        tick(90);
        expect(screen.getByRole('button', { name: 'Send code' })).toBeEnabled();
    });

    it('shows waits of an hour or more in hours', () => {
        render(<MfaSendCodeButton onSend={() => {}} retryAfter={5 * 3600} />);

        expect(screen.getByRole('button', { name: 'You can get a new code in 5:00:00' })).toBeDisabled();
    });
});
