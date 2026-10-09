import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaTotpSetupDialog, { type MfaTotpSetupDialogProps } from '../../stubs/inertia-react/components/totp-setup-dialog';

const codes = ['aaaaa-11111', 'bbbbb-22222'];
const base: MfaTotpSetupDialogProps = {
    open: true,
    secret: 'JBSWY3DPEHPK3PXP',
    qrSvg: '<svg data-testid="qr"></svg>',
    otpauthUrl: 'otpauth://totp/App:jane?secret=JBSWY3DPEHPK3PXP',
    onConfirm: () => {},
    onClose: () => {},
    onComplete: () => {},
};
const dialog = () => screen.getByRole('dialog');

describe('MfaTotpSetupDialog', () => {
    it('renders nothing while closed', () => {
        const { container } = render(<MfaTotpSetupDialog {...base} open={false} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('is a labelled modal dialog that takes focus and locks the page scroll', () => {
        const { unmount } = render(<MfaTotpSetupDialog {...base} />);

        expect(screen.getByRole('dialog', { name: 'Set up an authenticator app' })).toHaveAttribute('aria-modal', 'true');
        expect(dialog().contains(document.activeElement)).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');

        unmount();
        expect(document.body.style.overflow).toBe('');
    });

    it('step 1 shows the QR code, the key in groups with Copy, and on phones a link to the app', async () => {
        const user = userEvent.setup();
        render(<MfaTotpSetupDialog {...base} />);

        expect(screen.getByText('Step 1 of 3')).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toContainElement(screen.getByTestId('qr'));
        expect(screen.getByLabelText('Setup key')).toHaveTextContent('JBSW Y3DP EHPK 3PXP');
        expect(screen.getByRole('link', { name: 'Open in authenticator app' })).toHaveClass('sm:hidden');

        await user.click(screen.getByRole('button', { name: 'Copy' }));
        expect(await navigator.clipboard.readText()).toBe('JBSWY3DPEHPK3PXP');
    });

    it('goes to the code, back, and confirms the code once it has 6 digits', async () => {
        const onConfirm = vi.fn();
        render(<MfaTotpSetupDialog {...base} onConfirm={onConfirm} />);

        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(screen.getByRole('dialog', { name: 'Enter the code' })).toBeInTheDocument();
        const input = screen.getByRole('textbox', { name: 'Code from your authenticator app' });
        expect(input).toHaveFocus();

        await userEvent.type(input, '12 34 5');
        expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
        await userEvent.type(input, '6');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(onConfirm).toHaveBeenCalledWith('123456');

        await userEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText('Setup key')).toBeInTheDocument();
    });

    it('shows the error and keeps the code, selected, after a failed attempt', async () => {
        const { rerender } = render(<MfaTotpSetupDialog {...base} />);
        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
        await userEvent.type(screen.getByRole('textbox'), '111111');

        rerender(<MfaTotpSetupDialog {...base} processing />);
        rerender(<MfaTotpSetupDialog {...base} error="The provided code is invalid." />);

        expect(screen.getByRole('alert')).toHaveTextContent('The provided code is invalid.');
        const input = screen.getByRole('textbox') as HTMLInputElement;
        expect(input).toHaveValue('111111');
        // Kept so the user sees what they typed, and selected so typing replaces it.
        expect(input).toHaveFocus();
        expect([input.selectionStart, input.selectionEnd]).toEqual([0, 6]);
    });

    it('closes with the Close button or Escape before the code is confirmed', async () => {
        const onClose = vi.fn();
        render(<MfaTotpSetupDialog {...base} onClose={onClose} />);

        await userEvent.click(screen.getByRole('button', { name: 'Close' }));
        fireEvent.keyDown(dialog(), { key: 'Escape' });
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(3);
    });

    it('keeps Tab inside the dialog', () => {
        render(<MfaTotpSetupDialog {...base} />);
        const focusable = within(dialog()).getAllByRole('button').concat(within(dialog()).getAllByRole('link'));
        const buttons = within(dialog()).getAllByRole('button');
        const last = buttons[buttons.length - 1];
        last.focus();

        fireEvent.keyDown(last, { key: 'Tab' });

        expect(focusable).toContain(document.activeElement);
        expect(document.activeElement).not.toBe(last);
    });

    it('after the code: recovery codes, and Complete only once they are copied', async () => {
        const user = userEvent.setup();
        const onComplete = vi.fn();
        const onClose = vi.fn();
        render(<MfaTotpSetupDialog {...base} confirmed recoveryCodes={codes} onComplete={onComplete} onClose={onClose} />);

        expect(screen.getByRole('dialog', { name: 'Save your recovery codes' })).toBeInTheDocument();
        expect(screen.getByText('Step 3 of 3')).toBeInTheDocument();
        expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
        // Shown once: no way out but Complete.
        expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
        fireEvent.keyDown(dialog(), { key: 'Escape' });
        expect(onClose).not.toHaveBeenCalled();

        const complete = screen.getByRole('button', { name: 'Complete' });
        expect(complete).toBeDisabled();
        expect(screen.getByText('Copy or download your codes to finish.')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Copy' }));
        expect(await navigator.clipboard.readText()).toBe('aaaaa-11111\nbbbbb-22222');
        expect(complete).toBeEnabled();
        await user.click(complete);
        expect(onComplete).toHaveBeenCalledOnce();
    });

    it('downloads the codes as a text file, which also unlocks Complete', async () => {
        const createObjectURL = vi.fn((_blob: Blob) => 'blob:codes');
        const revokeObjectURL = vi.fn();
        Object.assign(URL, { createObjectURL, revokeObjectURL });
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            expect(this.download).toBe('acme-recovery-codes.txt');
            expect(this.href).toBe('blob:codes');
        });
        render(<MfaTotpSetupDialog {...base} confirmed recoveryCodes={codes} downloadName="acme-recovery-codes.txt" />);

        await userEvent.click(screen.getByRole('button', { name: 'Download' }));

        expect(click).toHaveBeenCalledOnce();
        expect(await createObjectURL.mock.calls[0][0].text()).toContain('aaaaa-11111\nbbbbb-22222');
        await waitFor(() => expect(revokeObjectURL).toHaveBeenCalledWith('blob:codes'), { timeout: 2000 });
        expect(screen.getByRole('button', { name: 'Downloaded' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Complete' })).toBeEnabled();
    });

    it('ends with a short "added" step when there are no new codes', async () => {
        const onComplete = vi.fn();
        render(<MfaTotpSetupDialog {...base} confirmed onComplete={onComplete} />);

        expect(screen.getByRole('dialog', { name: 'Authenticator app added' })).toBeInTheDocument();
        expect(screen.getByText('Step 2 of 2')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(onComplete).toHaveBeenCalledOnce();
    });

    it('starts over each time it opens', async () => {
        const { rerender } = render(<MfaTotpSetupDialog {...base} />);
        await userEvent.click(screen.getByRole('button', { name: 'Next' }));

        rerender(<MfaTotpSetupDialog {...base} open={false} />);
        act(() => rerender(<MfaTotpSetupDialog {...base} />));

        expect(screen.getByText('Step 1 of 3')).toBeInTheDocument();
    });

    it('falls back to a selected textarea when the Clipboard API is blocked, and says so when that fails too', async () => {
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: () => Promise.reject(new Error('blocked')) } });
        const execCommand = vi.fn(() => true);
        Object.assign(document, { execCommand });
        const { unmount } = render(<MfaTotpSetupDialog {...base} confirmed recoveryCodes={codes} />);

        await userEvent.click(screen.getByRole('button', { name: 'Copy' }));
        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(screen.getByRole('button', { name: 'Complete' })).toBeEnabled();
        unmount();

        execCommand.mockReturnValue(false);
        render(<MfaTotpSetupDialog {...base} confirmed recoveryCodes={codes} />);
        await userEvent.click(screen.getByRole('button', { name: 'Copy' }));
        expect(screen.getByRole('alert')).toHaveTextContent("Couldn't copy here. Download the codes instead.");
        expect(screen.getByRole('button', { name: 'Complete' })).toBeDisabled();
    });

    it('takes at most the 6 digits of an authenticator code', async () => {
        render(<MfaTotpSetupDialog {...base} />);
        await userEvent.click(screen.getByRole('button', { name: 'Next' }));
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
