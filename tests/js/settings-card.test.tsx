import { render, screen } from '@testing-library/react';
import MfaSettingsCard from '../../stubs/inertia-react/components/settings-card';

const card = () => screen.getByRole('region', { name: /Two-factor authentication/ });

describe('MfaSettingsCard', () => {
    it('offers to set up two-factor when it is off', () => {
        render(<MfaSettingsCard settingsUrl="/mfa/settings" />);

        expect(card()).toHaveTextContent('Off');
        expect(screen.getByRole('link', { name: 'Set up' })).toHaveAttribute('href', '/mfa/settings');
    });

    it('says when it is on, and links to manage it', () => {
        render(<MfaSettingsCard settingsUrl="/mfa/settings" hasMfa />);

        expect(card()).toHaveTextContent('On');
        expect(screen.getByRole('link', { name: 'Manage' })).toHaveAttribute('href', '/mfa/settings');
    });

    it('says when the account must set it up', () => {
        render(<MfaSettingsCard settingsUrl="/mfa/settings" mustEnroll />);

        expect(card()).toHaveTextContent('Required');
        expect(card()).toHaveTextContent('Your account requires a second sign-in step.');
        expect(screen.getByRole('link', { name: 'Set up' })).toBeInTheDocument();
    });

    it('renders nothing when MFA is off or there is no settings page', () => {
        const { container, rerender } = render(<MfaSettingsCard enabled={false} settingsUrl="/mfa/settings" />);
        expect(container).toBeEmptyDOMElement();

        rerender(<MfaSettingsCard settingsUrl={null} hasMfa />);
        expect(container).toBeEmptyDOMElement();
    });

    it('renders the link with a custom renderer (e.g. Inertia <Link>)', () => {
        const renderLink = vi.fn(({ href, className, children }) => (
            <button type="button" data-href={href} className={className}>
                {children}
            </button>
        ));
        render(<MfaSettingsCard settingsUrl="/mfa/settings" hasMfa renderLink={renderLink} />);

        expect(screen.getByRole('button', { name: 'Manage' })).toHaveAttribute('data-href', '/mfa/settings');
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(renderLink).toHaveBeenCalledWith(expect.objectContaining({ href: '/mfa/settings', className: expect.stringContaining('rounded-lg') }));
    });

    it('draws its own card, in light and dark, so it needs no wrapper', () => {
        render(<MfaSettingsCard settingsUrl="/mfa/settings" hasMfa />);

        expect(card().tagName).toBe('SECTION');
        expect(card()).toHaveClass('rounded-2xl', 'border', 'bg-white', 'p-4', 'sm:p-8', 'dark:bg-gray-900', 'dark:border-gray-800');
        expect(screen.getByRole('heading', { level: 2, name: 'Two-factor authentication' })).toBeInTheDocument();
    });

    it('replaces the card surface with className, keeping the content', () => {
        render(<MfaSettingsCard settingsUrl="/mfa/settings" hasMfa className="rounded-2xl bg-white p-4 shadow dark:bg-[#1E1F24]" />);

        expect(card()).toHaveClass('rounded-2xl', 'bg-white', 'p-4', 'shadow', 'dark:bg-[#1E1F24]');
        for (const surface of ['border', 'border-gray-200', 'dark:bg-gray-900', 'sm:p-8']) expect(card()).not.toHaveClass(surface);
        expect(card()).toHaveTextContent('On');
        expect(screen.getByRole('link', { name: 'Manage' })).toBeInTheDocument();
    });

    it('puts the action under the text, as a primary button only when there is something to set up', () => {
        const { rerender } = render(<MfaSettingsCard settingsUrl="/mfa/settings" hasMfa />);
        expect(screen.getByRole('link', { name: 'Manage' })).toHaveClass('border');
        expect(screen.getByRole('link', { name: 'Manage' })).not.toHaveClass('bg-gray-900');

        rerender(<MfaSettingsCard settingsUrl="/mfa/settings" />);
        expect(screen.getByRole('link', { name: 'Set up' })).toHaveClass('bg-gray-900', 'dark:bg-gray-100');
    });
});
