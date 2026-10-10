import { act, fireEvent, render, screen } from '@testing-library/react';
import MfaIdleWarning from '../../stubs/inertia-react/components/idle-warning';

const NOW = new Date('2026-10-10T12:00:00Z').getTime();
const iso = (ms: number) => new Date(NOW + ms).toISOString();

beforeEach(() => vi.useFakeTimers({ now: NOW, shouldAdvanceTime: false }));
afterEach(() => vi.useRealTimers());

const advance = (ms: number) => act(async () => void (await vi.advanceTimersByTimeAsync(ms)));

describe('MfaIdleWarning', () => {
    it('renders nothing without a deadline, and nothing until two minutes before it', async () => {
        const { container, rerender } = render(<MfaIdleWarning idleExpiresAt={null} onStay={async () => null} />);
        expect(container).toBeEmptyDOMElement();

        rerender(<MfaIdleWarning idleExpiresAt={iso(25 * 60_000)} onStay={async () => null} />);
        await advance(23 * 60_000 - 1);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();

        await advance(1);
        expect(screen.getByRole('region', { name: 'Still there?' })).toHaveTextContent('again in 2:00 unless you stay');
        await advance(15_000);
        expect(screen.getByRole('region')).toHaveTextContent('in 1:45');
    });

    it('asks the server first, and waits again when another tab was active', async () => {
        const refresh = vi.fn(async () => iso(40 * 60_000));
        render(<MfaIdleWarning idleExpiresAt={iso(25 * 60_000)} onStay={async () => null} refresh={refresh} />);

        await advance(23 * 60_000);
        expect(refresh).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();

        refresh.mockResolvedValue(iso(40 * 60_000));
        await advance(15 * 60_000);
        expect(refresh).toHaveBeenCalledTimes(2);
        expect(screen.getByRole('region', { name: 'Still there?' })).toBeInTheDocument();
    });

    it('checks again at the deadline, so activity in another tab during the warning keeps it alive', async () => {
        const refresh = vi.fn(async () => iso(25 * 60_000));
        render(<MfaIdleWarning idleExpiresAt={iso(25 * 60_000)} onStay={async () => null} refresh={refresh} />);
        await advance(23 * 60_000);
        expect(screen.getByRole('region', { name: 'Still there?' })).toBeInTheDocument();

        refresh.mockResolvedValue(iso(50 * 60_000));
        await advance(2 * 60_000);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();
    });

    it('"Stay signed in" hides it until two minutes before the new deadline', async () => {
        const onStay = vi.fn(async () => iso(50 * 60_000));
        render(<MfaIdleWarning idleExpiresAt={iso(25 * 60_000)} onStay={onStay} />);
        await advance(23 * 60_000);

        fireEvent.click(screen.getByRole('button', { name: 'Stay signed in' }));
        await advance(0);
        expect(onStay).toHaveBeenCalledOnce();
        expect(screen.queryByRole('region')).not.toBeInTheDocument();

        await advance(25 * 60_000); // 48 minutes in: two before the new deadline
        expect(screen.getByRole('region', { name: 'Still there?' })).toBeInTheDocument();
    });

    it('says the timeout has ended, at the deadline or when staying fails, and "Continue" calls onContinue', async () => {
        const onContinue = vi.fn();
        render(<MfaIdleWarning idleExpiresAt={iso(25 * 60_000)} onStay={async () => null} onContinue={onContinue} />);
        await advance(23 * 60_000);
        await advance(2 * 60_000);

        expect(screen.getByRole('region', { name: "Verify it's you" })).toHaveTextContent('You were away for a while.');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(onContinue).toHaveBeenCalledOnce();
    });

    it('shows as ended when "Stay signed in" fails', async () => {
        render(<MfaIdleWarning idleExpiresAt={iso(25 * 60_000)} onStay={async () => null} />);
        await advance(23 * 60_000);

        fireEvent.click(screen.getByRole('button', { name: 'Stay signed in' }));
        await advance(0);
        expect(screen.getByRole('region', { name: "Verify it's you" })).toBeInTheDocument();
    });

    it('takes its copy, warning time and position from props', async () => {
        render(
            <MfaIdleWarning
                idleExpiresAt={iso(10 * 60_000)}
                onStay={async () => null}
                warnSeconds={300}
                title="Noch da?"
                body="Code in :countdown"
                stayLabel="Bleiben"
                position="bottom-right"
            />,
        );
        await advance(5 * 60_000);

        expect(screen.getByRole('region', { name: 'Noch da?' })).toHaveTextContent('Code in 5:00');
        expect(screen.getByRole('region').className).toContain('sm:right-6');
        expect(screen.getByRole('button', { name: 'Bleiben' })).toBeInTheDocument();
    });
});
