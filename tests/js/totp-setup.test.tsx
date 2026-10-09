import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaTotpSetup from '../../stubs/inertia-react/components/totp-setup';

const svg = '<svg xmlns="http://www.w3.org/2000/svg" data-testid="qr"><rect width="1" height="1"/></svg>';

describe('MfaTotpSetup', () => {
    it('shows the QR code and the key', () => {
        render(<MfaTotpSetup secret="JBSWY3DPEHPK3PXP" qrSvg={svg} onConfirm={() => {}} />);

        expect(screen.getByRole('heading')).toHaveTextContent('Finish setting up authenticator app');
        expect(screen.getByRole('img', { name: /QR code/ })).toContainElement(screen.getByTestId('qr'));
        expect(screen.getByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument();
    });

    it('works without a QR code', () => {
        render(<MfaTotpSetup secret="JBSWY3DPEHPK3PXP" onConfirm={() => {}} label="Authenticator" />);

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.getByRole('heading')).toHaveTextContent('Finish setting up authenticator');
    });

    it('confirms with the digits entered', async () => {
        const onConfirm = vi.fn();
        render(<MfaTotpSetup secret="S" onConfirm={onConfirm} />);
        const input = screen.getByRole('textbox', { name: 'Code from your authenticator app' });
        const confirm = screen.getByRole('button', { name: 'Confirm' });

        await userEvent.type(input, '12 3');
        expect(confirm).toBeDisabled();
        await userEvent.type(input, '456');
        await userEvent.click(confirm);

        expect(onConfirm).toHaveBeenCalledWith('123456');
    });

    it('is disabled while confirming', async () => {
        render(<MfaTotpSetup secret="S" onConfirm={() => {}} processing />);
        await userEvent.type(screen.getByRole('textbox'), '123456');

        expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
    });

    it('shows the error and clears the input after a failed attempt', async () => {
        const { rerender } = render(<MfaTotpSetup secret="S" onConfirm={() => {}} />);
        await userEvent.type(screen.getByRole('textbox'), '123456');

        rerender(<MfaTotpSetup secret="S" onConfirm={() => {}} processing />);
        rerender(<MfaTotpSetup secret="S" onConfirm={() => {}} error="Invalid code." />);

        expect(screen.getByRole('alert')).toHaveTextContent('Invalid code.');
        expect(screen.getByRole('textbox')).toHaveValue('');
    });
});
