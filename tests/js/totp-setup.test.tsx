import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaTotpSetup from '../../stubs/inertia-react/components/totp-setup';

const svg = '<svg xmlns="http://www.w3.org/2000/svg" data-testid="qr"><rect width="1" height="1"/></svg>';

describe('MfaTotpSetup', () => {
    it('shows the QR code and the key', () => {
        render(<MfaTotpSetup secret="JBSWY3DPEHPK3PXP" qrSvg={svg} onConfirm={() => {}} />);

        expect(screen.getByRole('heading')).toHaveTextContent('Finish setting up authenticator app');
        expect(screen.getByRole('img', { name: /QR code/ })).toContainElement(screen.getByTestId('qr'));
        expect(screen.getByLabelText('Setup key')).toHaveTextContent('JBSW Y3DP EHPK 3PXP');
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

    it('drops its own box and heading inside a card (framed={false})', () => {
        render(<MfaTotpSetup secret="S" onConfirm={() => {}} framed={false} />);

        expect(screen.queryByRole('heading')).not.toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Finish setting up authenticator app' })).not.toHaveClass('border');
    });

    it('copies the key without the spaces', async () => {
        const user = userEvent.setup();
        render(<MfaTotpSetup secret="JBSWY3DPEHPK3PXP" onConfirm={() => {}} />);

        await user.click(screen.getByRole('button', { name: 'Copy' }));

        expect(await navigator.clipboard.readText()).toBe('JBSWY3DPEHPK3PXP');
        expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
    });

    it('links to the authenticator app when given the otpauth URL (shown on phones)', () => {
        const url = 'otpauth://totp/App:jane%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=App';
        const { rerender } = render(<MfaTotpSetup secret="S" onConfirm={() => {}} />);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();

        rerender(<MfaTotpSetup secret="S" otpauthUrl={url} onConfirm={() => {}} />);

        const link = screen.getByRole('link', { name: 'Open in authenticator app' });
        expect(link).toHaveAttribute('href', url);
        expect(link).toHaveClass('sm:hidden');
    });

    it('takes at most the 6 digits of an authenticator code', async () => {
        render(<MfaTotpSetup secret="S" onConfirm={() => {}} />);
        const input = screen.getByRole('textbox', { name: 'Code from your authenticator app' });

        await userEvent.type(input, '1234567890');

        expect(input).toHaveValue('123456');

        // Some apps copy codes as "123 456": the digits all survive a paste.
        await userEvent.clear(input);
        await userEvent.click(input);
        await userEvent.paste('654 321');
        expect(input).toHaveValue('654321');
    });
});
