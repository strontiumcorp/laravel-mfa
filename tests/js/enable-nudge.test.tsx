import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaEnableNudge, { type MfaEnableNudgeProps } from '../../stubs/inertia-react/components/enable-nudge';

const props: MfaEnableNudgeProps = {
    show: true,
    title: 'Protect your account',
    body: 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
    button: 'Turn on',
    dismissLabel: 'Not today',
    settingsUrl: '/mfa/settings',
    onDismiss: () => {},
};

const card = () => screen.getByRole('region', { name: 'Protect your account' });

afterEach(() => vi.restoreAllMocks());

describe('MfaEnableNudge', () => {
    it('renders nothing when it should not show', () => {
        const { container } = render(<MfaEnableNudge {...props} show={false} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('shows the title, body and a link to the settings page', () => {
        render(<MfaEnableNudge {...props} />);

        expect(card()).toHaveAccessibleDescription(props.body);
        expect(screen.getByRole('link', { name: 'Turn on' })).toHaveAttribute('href', '/mfa/settings');
        expect(screen.getByRole('button', { name: 'Not today' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Dismiss for today' })).toBeInTheDocument();
    });

    it('does not take the focus', () => {
        render(<MfaEnableNudge {...props} />);

        expect(document.body).toHaveFocus();
    });

    it('renders the link with a custom renderer (e.g. Inertia <Link>)', () => {
        const renderLink = vi.fn(({ href, className, children }) => (
            <a href={href} className={className} data-custom="">
                {children}
            </a>
        ));
        render(<MfaEnableNudge {...props} renderLink={renderLink} />);

        expect(renderLink).toHaveBeenCalledWith(expect.objectContaining({ href: '/mfa/settings', children: 'Turn on' }));
        expect(screen.getByRole('link', { name: 'Turn on' })).toHaveAttribute('data-custom');
    });

    it('has no primary button without a settings page', () => {
        render(<MfaEnableNudge {...props} settingsUrl={null} />);

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it.each(['Not today', 'Dismiss for today'])('"%s" hides it at once and passes the browser timezone', async (name) => {
        const onDismiss = vi.fn();
        render(<MfaEnableNudge {...props} onDismiss={onDismiss} />);

        await userEvent.click(screen.getByRole('button', { name }));

        expect(onDismiss).toHaveBeenCalledWith(Intl.DateTimeFormat().resolvedOptions().timeZone);
        expect(typeof onDismiss.mock.calls[0][0]).toBe('string');
        expect(screen.queryByRole('region')).not.toBeInTheDocument();
    });

    it('passes undefined when the browser can\'t tell its timezone', async () => {
        vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(() => {
            throw new Error('no Intl');
        });
        const onDismiss = vi.fn();
        render(<MfaEnableNudge {...props} onDismiss={onDismiss} />);

        await userEvent.click(screen.getByRole('button', { name: 'Not today' }));

        expect(onDismiss).toHaveBeenCalledWith(undefined);
    });

    it('shows again once the server hid it and later shows it (the next day)', async () => {
        const { rerender } = render(<MfaEnableNudge {...props} />);
        await userEvent.click(screen.getByRole('button', { name: 'Not today' }));

        rerender(<MfaEnableNudge {...props} />);
        expect(screen.queryByRole('region')).not.toBeInTheDocument();

        rerender(<MfaEnableNudge {...props} show={false} />);
        rerender(<MfaEnableNudge {...props} />);
        expect(card()).toBeInTheDocument();
    });

    describe('disabled (e.g. while impersonating)', () => {
        it('still shows, with its link', () => {
            render(<MfaEnableNudge {...props} disabled />);

            expect(card()).toBeInTheDocument();
            expect(screen.getByRole('link', { name: 'Turn on' })).toHaveAttribute('href', '/mfa/settings');
        });

        it.each(['Not today', 'Dismiss for today'])('"%s" hides it for this page view only, without onDismiss', async (name) => {
            const onDismiss = vi.fn();
            const { unmount } = render(<MfaEnableNudge {...props} onDismiss={onDismiss} disabled />);

            await userEvent.click(screen.getByRole('button', { name }));

            expect(screen.queryByRole('region')).not.toBeInTheDocument();
            expect(onDismiss).not.toHaveBeenCalled();
            // The next page (a fresh mount) shows it again.
            unmount();
            render(<MfaEnableNudge {...props} onDismiss={onDismiss} disabled />);
            expect(card()).toBeInTheDocument();
        });
    });

    describe('position and offset', () => {
        const style = () => card().style;
        const x = () => style().getPropertyValue('--mfa-nudge-x');

        it('floats 24px from the bottom-right corner by default', () => {
            render(<MfaEnableNudge {...props} />);

            expect(style().bottom).toBe('24px');
            expect(style().top).toBe('');
            expect(x()).toBe('24px');
            expect(card()).toHaveClass('fixed', 'sm:right-[var(--mfa-nudge-x)]', 'sm:left-auto');
        });

        it.each([
            ['top-left', 'top', 'sm:left-[var(--mfa-nudge-x)]'],
            ['top-right', 'top', 'sm:right-[var(--mfa-nudge-x)]'],
            ['bottom-left', 'bottom', 'sm:left-[var(--mfa-nudge-x)]'],
            ['bottom-right', 'bottom', 'sm:right-[var(--mfa-nudge-x)]'],
        ] as const)('%s uses the %s edge', (position, edge, horizontal) => {
            render(<MfaEnableNudge {...props} position={position} offset={16} />);

            expect(style()[edge]).toBe('16px');
            expect(style()[edge === 'top' ? 'bottom' : 'top']).toBe('');
            expect(x()).toBe('16px');
            expect(card()).toHaveClass(horizontal);
        });

        it.each([
            ['top-center', 'top'],
            ['bottom-center', 'bottom'],
        ] as const)('%s centres it horizontally', (position, edge) => {
            render(<MfaEnableNudge {...props} position={position} />);

            expect(style()[edge]).toBe('24px');
            expect(card()).toHaveClass('sm:left-0', 'sm:right-0', 'sm:mx-auto');
            expect(card()).not.toHaveClass('sm:right-[var(--mfa-nudge-x)]');
        });

        it('takes a pair as [x, y], numbers as px and strings as any CSS length', () => {
            render(<MfaEnableNudge {...props} position="top-left" offset={['2rem', 72]} />);

            expect(x()).toBe('2rem');
            expect(style().top).toBe('72px');
        });

        it('takes a single string for both axes', () => {
            render(<MfaEnableNudge {...props} offset="1.5rem" />);

            expect(x()).toBe('1.5rem');
            expect(style().bottom).toBe('1.5rem');
        });

        it('spans phones with a 16px gutter, and is 320px wide from sm up', () => {
            render(<MfaEnableNudge {...props} className="z-40" />);

            expect(card()).toHaveClass('left-4', 'right-4', 'sm:w-80', 'z-40');
        });
    });
});
