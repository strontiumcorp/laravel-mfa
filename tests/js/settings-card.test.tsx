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
        expect(renderLink).toHaveBeenCalledWith(expect.objectContaining({ href: '/mfa/settings', children: 'Manage' }));
    });

    it('accepts extra classes', () => {
        render(<MfaSettingsCard settingsUrl="/mfa/settings" className="mt-4" />);

        expect(card()).toHaveClass('mt-4');
    });
});
