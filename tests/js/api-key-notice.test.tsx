import { render, screen } from '@testing-library/react';
import MfaApiKeyNotice from '../../stubs/inertia-react/components/api-key-notice';

describe('MfaApiKeyNotice', () => {
    it('warns that API keys skip MFA', () => {
        render(<MfaApiKeyNotice />);

        expect(screen.getByRole('note')).toHaveTextContent("Two-factor authentication isn't enforced for API keys.");
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('links to the settings page when given a URL', () => {
        render(<MfaApiKeyNotice settingsUrl="/user/two-factor" />);

        expect(screen.getByRole('link', { name: 'Turn on two-factor authentication' })).toHaveAttribute('href', '/user/two-factor');
    });

    it('renders nothing when MFA is switched off', () => {
        const { container } = render(<MfaApiKeyNotice enabled={false} settingsUrl="/user/two-factor" />);

        expect(container).toBeEmptyDOMElement();
    });

    it('accepts extra classes', () => {
        render(<MfaApiKeyNotice className="mt-4" />);

        expect(screen.getByRole('note')).toHaveClass('mt-4');
    });
});
