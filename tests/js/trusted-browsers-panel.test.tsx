import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaTrustedBrowsersPanel, { type MfaTrustedBrowser } from '../../stubs/inertia-react/components/trusted-browsers-panel';

const NOW = new Date('2026-10-10T12:00:00Z');
const browsers: MfaTrustedBrowser[] = [
    { id: 3, label: 'Chrome on Mac', created_at: '2026-10-08T12:00:00Z', last_used_at: '2026-10-10T09:00:00Z', expires_at: '2026-11-07T12:00:00Z', current: true },
    { id: 2, label: null, created_at: '2026-10-01T12:00:00Z', last_used_at: null, expires_at: '2026-10-31T12:00:00Z', current: false },
    { id: 1, label: 'Safari on iPhone', created_at: null, last_used_at: '2026-08-01T12:00:00Z', expires_at: '2026-10-15T12:00:00Z', current: false },
];
const date = (iso: string) => new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(iso));
const rows = () => within(screen.getByRole('list', { name: 'Trusted browsers' })).getAllByRole('listitem');

beforeEach(() => vi.useFakeTimers({ now: NOW, toFake: ['Date'] }));
afterEach(() => vi.useRealTimers());

describe('MfaTrustedBrowsersPanel', () => {
    it('lists each browser with when it was used and until when it is trusted', () => {
        render(<MfaTrustedBrowsersPanel browsers={browsers} onForget={() => {}} />);
        const [chrome, unknown, safari] = rows();

        expect(screen.getByText('These browsers skip the code at sign-in. Your password is still needed.')).toBeInTheDocument();
        expect(chrome).toHaveTextContent('Chrome on Mac');
        expect(within(chrome).getByText('This browser')).toBeInTheDocument();
        expect(chrome).toHaveTextContent(`Last used 3 hours ago · Trusted until ${date('2026-11-07T12:00:00Z')}`);

        expect(unknown).toHaveTextContent('Unknown browser');
        expect(within(unknown).queryByText('This browser')).not.toBeInTheDocument();
        expect(unknown).toHaveTextContent(`Added ${date('2026-10-01T12:00:00Z')} · Trusted until ${date('2026-10-31T12:00:00Z')}`);

        // Used more than a week ago: a date. No date at all: only the expiry.
        expect(safari).toHaveTextContent(`Last used ${date('2026-08-01T12:00:00Z')} · Trusted until`);
    });

    it('says "yesterday" and "just now"', () => {
        render(
            <MfaTrustedBrowsersPanel
                browsers={[
                    { ...browsers[1], id: 5, last_used_at: '2026-10-09T10:00:00Z' },
                    { ...browsers[1], id: 6, last_used_at: '2026-10-10T11:59:40Z' },
                ]}
                onForget={() => {}}
            />,
        );

        expect(rows()[0]).toHaveTextContent('Last used yesterday');
        expect(rows()[1]).toHaveTextContent('Last used just now');
    });

    it('falls back to the ISO date without Intl', () => {
        const original = globalThis.Intl;
        vi.stubGlobal('Intl', undefined);
        try {
            render(<MfaTrustedBrowsersPanel browsers={[browsers[0]]} onForget={() => {}} />);
        } finally {
            vi.stubGlobal('Intl', original);
        }

        expect(rows()[0]).toHaveTextContent('Last used 2026-10-10 · Trusted until 2026-11-07');
    });

    it('forgets one browser, or all of them', async () => {
        const onForget = vi.fn();
        const onForgetAll = vi.fn();
        render(<MfaTrustedBrowsersPanel browsers={browsers} onForget={onForget} onForgetAll={onForgetAll} />);

        await userEvent.click(screen.getByRole('button', { name: 'Forget Unknown browser' }));
        await userEvent.click(screen.getByRole('button', { name: 'Forget this browser' }));
        await userEvent.click(screen.getByRole('button', { name: 'Forget all' }));

        expect(onForget.mock.calls).toEqual([[2], [3]]);
        expect(onForgetAll).toHaveBeenCalledOnce();
    });

    it('offers "Forget all" only for more than one browser, when given', () => {
        const { rerender } = render(<MfaTrustedBrowsersPanel browsers={browsers} onForget={() => {}} />);
        expect(screen.queryByRole('button', { name: 'Forget all' })).not.toBeInTheDocument();

        rerender(<MfaTrustedBrowsersPanel browsers={[browsers[0]]} onForget={() => {}} onForgetAll={() => {}} />);
        expect(screen.queryByRole('button', { name: 'Forget all' })).not.toBeInTheDocument();
    });

    it('waits while one is being forgotten', () => {
        render(<MfaTrustedBrowsersPanel browsers={browsers} onForget={() => {}} onForgetAll={() => {}} forgettingId={2} />);

        expect(screen.getByRole('button', { name: 'Forget Unknown browser' })).toHaveTextContent('Forgetting…');
        expect(screen.getByRole('button', { name: 'Forget Unknown browser' })).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByRole('button', { name: 'Forget this browser' })).toHaveTextContent('Forget');
        for (const button of screen.getAllByRole('button')) expect(button).toBeDisabled();
    });

    it('waits while all are being forgotten', () => {
        render(<MfaTrustedBrowsersPanel browsers={browsers} onForget={() => {}} onForgetAll={() => {}} forgettingAll />);

        expect(screen.getByRole('button', { name: 'Forgetting…' })).toBeDisabled();
        for (const button of screen.getAllByRole('button')) expect(button).toBeDisabled();
    });

    it('says when there are none', () => {
        render(<MfaTrustedBrowsersPanel browsers={[]} onForget={() => {}} onForgetAll={() => {}} className="mt-4" />);

        expect(screen.queryByRole('list')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.getByText(/None yet\./)).toBeInTheDocument();
        expect(screen.getByText(/None yet\./).parentElement).toHaveClass('mt-4');
    });
});
