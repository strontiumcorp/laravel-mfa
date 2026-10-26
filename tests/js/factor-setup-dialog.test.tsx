import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaFactorSetupDialog, { type MfaFactorSetupDialogProps } from '../../stubs/inertia-react/components/factor-setup-dialog';

const codes = ['aaaaa-11111', 'bbbbb-22222'];
const totp: MfaFactorSetupDialogProps = {
    open: true,
    type: 'totp',
    label: 'Authenticator app',
    secret: 'JBSWY3DPEHPK3PXP',
    qrSvg: '<svg data-testid="qr"></svg>',
    otpauthUrl: 'otpauth://totp/App:jane?secret=JBSWY3DPEHPK3PXP',
    onConfirm: () => {},
    onClose: () => {},
    onComplete: () => {},
};
const sms: MfaFactorSetupDialogProps = { open: true, type: 'sms', label: 'SMS', onConfirm: () => {}, onClose: () => {}, onComplete: () => {} };
const dialog = () => screen.getByRole('dialog');
const selected = (input: HTMLElement) => [(input as HTMLInputElement).selectionStart, (input as HTMLInputElement).selectionEnd];

describe('MfaFactorSetupDialog', () => {
    it('renders nothing while closed', () => {
        const { container } = render(<MfaFactorSetupDialog {...totp} open={false} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('is a labelled modal dialog that takes focus and locks the page scroll', () => {
        const { unmount } = render(<MfaFactorSetupDialog {...totp} />);

        expect(screen.getByRole('dialog', { name: 'Set up an authenticator app' })).toHaveAttribute('aria-modal', 'true');
        expect(dialog().contains(document.activeElement)).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');

        unmount();
        expect(document.body.style.overflow).toBe('');
    });

    describe('password first', () => {
        it('asks for the password as step 1 when needed, and keeps the step numbering after it', async () => {
            const onConfirmPassword = vi.fn();
            const { rerender } = render(<MfaFactorSetupDialog {...sms} askPassword onConfirmPassword={onConfirmPassword} />);

            expect(screen.getByRole('dialog', { name: 'Confirm your password' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 1 of 4')).toBeInTheDocument();
            expect(screen.getByLabelText('Password')).toHaveFocus();
            expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();

            await userEvent.type(screen.getByLabelText('Password'), 'secret{Enter}');
            expect(onConfirmPassword).toHaveBeenCalledWith('secret');

            rerender(<MfaFactorSetupDialog {...sms} askPassword={false} onConfirmPassword={onConfirmPassword} />);
            expect(screen.getByRole('dialog', { name: 'Add a phone number' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 2 of 4')).toBeInTheDocument();
        });

        it('clears a wrong password and counts down when locked out', async () => {
            const { rerender } = render(<MfaFactorSetupDialog {...sms} askPassword />);
            await userEvent.type(screen.getByLabelText('Password'), 'wrong');

            rerender(<MfaFactorSetupDialog {...sms} askPassword passwordProcessing />);
            rerender(<MfaFactorSetupDialog {...sms} askPassword passwordError="The provided password is incorrect." passwordRetryAfter={60} />);

            expect(screen.getByRole('alert')).toHaveTextContent('The provided password is incorrect.');
            expect(screen.getByLabelText('Password')).toHaveValue('');
            expect(screen.getByRole('button', { name: 'Try again in 1:00' })).toBeDisabled();
        });

        it('brings the password step back when the server asks for it mid-way', () => {
            const { rerender } = render(<MfaFactorSetupDialog {...sms} />);
            expect(screen.getByText('SMS · Step 1 of 3')).toBeInTheDocument();

            rerender(<MfaFactorSetupDialog {...sms} askPassword />);

            expect(screen.getByRole('dialog', { name: 'Confirm your password' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 1 of 4')).toBeInTheDocument();
        });
    });

    describe("confirm it's you", () => {
        const check = { email: 'j***@example.com' };

        it('offers to email a code to the account, then asks for it', async () => {
            const onSendEmailCode = vi.fn();
            const onVerifyEmailCode = vi.fn();
            const { rerender } = render(<MfaFactorSetupDialog {...totp} verifyEmail={check} onSendEmailCode={onSendEmailCode} onVerifyEmailCode={onVerifyEmailCode} />);

            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
            expect(screen.getByText('Authenticator app · Step 1 of 4')).toBeInTheDocument();
            expect(dialog()).toHaveTextContent("Before you add your first sign-in method, we'll email a code to j***@example.com to check it's you.");
            expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
            const send = screen.getByRole('button', { name: 'Email me a code' });
            expect(send).toHaveFocus();
            await userEvent.click(send);
            expect(onSendEmailCode).toHaveBeenCalledOnce();

            rerender(<MfaFactorSetupDialog {...totp} verifyEmail={check} onSendEmailCode={onSendEmailCode} onVerifyEmailCode={onVerifyEmailCode} emailCodeSent />);
            expect(dialog()).toHaveTextContent('We sent a code to j***@example.com.');
            const input = screen.getByRole('textbox', { name: 'Code from the email' });
            expect(input).toHaveFocus();
            expect(input).toHaveAttribute('inputmode', 'numeric');
            expect(input).toHaveAttribute('autocomplete', 'one-time-code');
            expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
            await userEvent.type(input, '12a3456{Enter}');
            expect(onVerifyEmailCode).toHaveBeenCalledWith('123456');
            expect(onSendEmailCode).toHaveBeenCalledOnce();
        });

        it('moves on to the setup once checked, keeping the step numbering', () => {
            const { rerender } = render(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent />);

            rerender(<MfaFactorSetupDialog {...totp} verifyEmail={null} emailCodeSent />);

            expect(screen.getByRole('dialog', { name: 'Set up an authenticator app' })).toBeInTheDocument();
            expect(screen.getByText('Authenticator app · Step 2 of 4')).toBeInTheDocument();
        });

        it('comes after the password: password, check, number, code, done', () => {
            const { rerender } = render(<MfaFactorSetupDialog {...sms} askPassword verifyEmail={check} />);
            expect(screen.getByRole('dialog', { name: 'Confirm your password' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 1 of 5')).toBeInTheDocument();

            rerender(<MfaFactorSetupDialog {...sms} verifyEmail={check} />);
            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 2 of 5')).toBeInTheDocument();

            rerender(<MfaFactorSetupDialog {...sms} />);
            expect(screen.getByRole('dialog', { name: 'Add a phone number' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 3 of 5')).toBeInTheDocument();
        });

        it('comes back when the server asks for it mid-way', () => {
            const { rerender } = render(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" />);
            expect(screen.getByText('SMS · Step 2 of 3')).toBeInTheDocument();

            rerender(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" verifyEmail={check} />);

            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 1 of 4')).toBeInTheDocument();
        });

        it('counts down to a resend, and to another send after a refusal', () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            const onSendEmailCode = vi.fn();
            const { rerender } = render(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent emailCodeRetryAfter={2} onSendEmailCode={onSendEmailCode} />);

            expect(screen.getByRole('button', { name: 'Resend in 0:02' })).toBeDisabled();
            act(() => vi.advanceTimersByTime(1000));
            act(() => vi.advanceTimersByTime(1000));
            fireEvent.click(screen.getByRole('button', { name: 'Resend code' }));
            expect(onSendEmailCode).toHaveBeenCalledOnce();

            rerender(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeRetryAfter={65} emailCodeError="Please wait before requesting another code." />);
            expect(screen.getByRole('alert')).toHaveTextContent('Please wait before requesting another code.');
            expect(screen.getByRole('button', { name: 'Try again in 1:05' })).toBeDisabled();
        });

        it('keeps a wrong code, selected, with the error', async () => {
            const { rerender } = render(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent />);
            await userEvent.type(screen.getByRole('textbox'), '111111');

            rerender(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent emailCodeProcessing />);
            expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
            rerender(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent emailCodeError="The provided code is invalid." />);

            const input = screen.getByRole('textbox');
            expect(screen.getByRole('alert')).toHaveTextContent('The provided code is invalid.');
            expect(input).toHaveValue('111111');
            expect(input).toHaveFocus();
            expect(selected(input)).toEqual([0, 6]);
        });

        it('empties the code when a new one is sent', async () => {
            const { rerender } = render(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent />);
            await userEvent.type(screen.getByRole('textbox'), '111111');

            rerender(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent emailCodeSending />);
            rerender(<MfaFactorSetupDialog {...totp} verifyEmail={check} emailCodeSent />);

            expect(screen.getByRole('textbox')).toHaveValue('');
        });

        it('without an email, says to ask an administrator and offers nothing to submit', async () => {
            const onClose = vi.fn();
            const onSendEmailCode = vi.fn();
            render(<MfaFactorSetupDialog {...totp} verifyEmail={{ email: null }} onSendEmailCode={onSendEmailCode} onClose={onClose} />);

            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toHaveTextContent('Ask an administrator for a setup link to add your first sign-in method.');
            expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
            expect(within(dialog()).getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent)).toEqual(['Close', 'Cancel']);

            fireEvent.submit(dialog().querySelector('form')!);
            expect(onSendEmailCode).not.toHaveBeenCalled();
            await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            expect(onClose).toHaveBeenCalledOnce();
        });
    });

    describe('authenticator app', () => {
        it('waits for its key, then shows the QR code, the key with Copy, and on phones a link to the app', async () => {
            const user = userEvent.setup();
            const { rerender } = render(<MfaFactorSetupDialog {...totp} secret={null} qrSvg={null} />);
            expect(screen.getByRole('status')).toHaveTextContent('Getting your setup key');
            expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();

            rerender(<MfaFactorSetupDialog {...totp} />);
            expect(screen.getByText('Authenticator app · Step 1 of 3')).toBeInTheDocument();
            expect(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toContainElement(screen.getByTestId('qr'));
            expect(screen.getByLabelText('Setup key')).toHaveTextContent('JBSW Y3DP EHPK 3PXP');
            expect(screen.getByRole('link', { name: 'Open in authenticator app' })).toHaveClass('sm:hidden');

            await user.click(screen.getByRole('button', { name: 'Copy' }));
            expect(await navigator.clipboard.readText()).toBe('JBSWY3DPEHPK3PXP');
        });

        it('goes to a 6-digit code, back, and confirms it', async () => {
            const onConfirm = vi.fn();
            render(<MfaFactorSetupDialog {...totp} onConfirm={onConfirm} />);

            await userEvent.click(screen.getByRole('button', { name: 'Next' }));
            const input = screen.getByRole('textbox', { name: 'Code from your authenticator app' });
            expect(input).toHaveFocus();

            await userEvent.type(input, '12345');
            expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled();
            await userEvent.type(input, '67890');
            expect(input).toHaveValue('123456');
            await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));
            expect(onConfirm).toHaveBeenCalledWith('123456');

            await userEvent.click(screen.getByRole('button', { name: 'Back' }));
            expect(screen.getByLabelText('Setup key')).toBeInTheDocument();
        });

        it('keeps a wrong code, selected', async () => {
            const { rerender } = render(<MfaFactorSetupDialog {...totp} />);
            await userEvent.click(screen.getByRole('button', { name: 'Next' }));
            await userEvent.type(screen.getByRole('textbox'), '111111');

            rerender(<MfaFactorSetupDialog {...totp} processing />);
            rerender(<MfaFactorSetupDialog {...totp} error="The provided code is invalid." />);

            const input = screen.getByRole('textbox');
            expect(screen.getByRole('alert')).toHaveTextContent('The provided code is invalid.');
            expect(input).toHaveValue('111111');
            expect(input).toHaveFocus();
            expect(selected(input)).toEqual([0, 6]);
        });
    });

    describe('email and SMS', () => {
        it('asks for the number, then the code that was sent to it', async () => {
            const onSubmitDestination = vi.fn();
            const { rerender } = render(<MfaFactorSetupDialog {...sms} onSubmitDestination={onSubmitDestination} />);

            expect(screen.getByRole('dialog', { name: 'Add a phone number' })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Send code' })).toBeDisabled();
            await userEvent.type(screen.getByLabelText('Phone number, with country code'), ' +15555550100 {Enter}');
            expect(onSubmitDestination).toHaveBeenCalledWith('+15555550100');

            rerender(<MfaFactorSetupDialog {...sms} onSubmitDestination={onSubmitDestination} sentTo="+*******0100" />);
            expect(screen.getByRole('dialog', { name: 'Enter the code' })).toBeInTheDocument();
            expect(dialog()).toHaveTextContent('We sent a code to +*******0100.');
            expect(screen.getByRole('textbox', { name: 'Verification code' })).toHaveFocus();
        });

        it('goes on to the code after re-sending to the same number', async () => {
            const { rerender } = render(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" />);
            await userEvent.click(screen.getByRole('button', { name: 'Use another number' }));
            await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100');

            rerender(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" destinationProcessing />);
            rerender(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" />);

            expect(screen.getByRole('dialog', { name: 'Enter the code' })).toBeInTheDocument();
        });

        it('lets email go out to the account email when left empty', async () => {
            const onSubmitDestination = vi.fn();
            render(<MfaFactorSetupDialog {...sms} type="email" label="Email" onSubmitDestination={onSubmitDestination} />);

            expect(screen.getByRole('dialog', { name: 'Add an email address' })).toBeInTheDocument();
            await userEvent.click(screen.getByRole('button', { name: 'Send code' }));

            expect(onSubmitDestination).toHaveBeenCalledWith('');
        });

        it('keeps a refused number, selected, with the error', async () => {
            const { rerender } = render(<MfaFactorSetupDialog {...sms} />);
            await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+19005550100');

            rerender(<MfaFactorSetupDialog {...sms} destinationProcessing />);
            rerender(<MfaFactorSetupDialog {...sms} destinationError="We can't send verification codes to this destination." />);

            const input = screen.getByLabelText('Phone number, with country code');
            expect(screen.getByRole('alert')).toHaveTextContent("We can't send verification codes to this destination.");
            expect(input).toHaveValue('+19005550100');
            expect(selected(input)).toEqual([0, 12]);
        });

        it('resends after the countdown, and goes back to change the number', async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            const onResend = vi.fn();
            render(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" retryAfter={2} onResend={onResend} />);

            expect(screen.getByRole('button', { name: 'Resend in 0:02' })).toBeDisabled();
            act(() => vi.advanceTimersByTime(1000));
            act(() => vi.advanceTimersByTime(1000));
            fireEvent.click(screen.getByRole('button', { name: 'Resend code' }));
            expect(onResend).toHaveBeenCalledOnce();

            fireEvent.click(screen.getByRole('button', { name: 'Use another number' }));
            expect(screen.getByRole('dialog', { name: 'Add a phone number' })).toBeInTheDocument();
            fireEvent.click(screen.getByRole('button', { name: 'Back' }));
            expect(screen.getByRole('dialog', { name: 'Enter the code' })).toBeInTheDocument();
        });
    });

    it('closes with Close, Escape or Cancel before the code is confirmed', async () => {
        const onClose = vi.fn();
        render(<MfaFactorSetupDialog {...totp} onClose={onClose} />);

        await userEvent.click(screen.getByRole('button', { name: 'Close' }));
        fireEvent.keyDown(dialog(), { key: 'Escape' });
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(3);
    });

    it('keeps Tab inside the dialog', () => {
        render(<MfaFactorSetupDialog {...totp} />);
        const buttons = within(dialog()).getAllByRole('button');
        const last = buttons[buttons.length - 1];
        last.focus();

        fireEvent.keyDown(last, { key: 'Tab' });

        expect(dialog().contains(document.activeElement)).toBe(true);
        expect(document.activeElement).not.toBe(last);
    });

    describe('after the code', () => {
        it('shows the recovery codes with Download only, and Finish once they are downloaded', async () => {
            const onComplete = vi.fn();
            const onClose = vi.fn();
            const onDownload = vi.fn();
            const { rerender } = render(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" confirmed recoveryCodes={codes} onDownload={onDownload} onComplete={onComplete} onClose={onClose} />);

            expect(screen.getByRole('dialog', { name: 'Save your recovery codes' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 3 of 3')).toBeInTheDocument();
            expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
            expect(screen.getByText(/Download them and keep the file somewhere safe, not only on this device\./)).toBeInTheDocument();
            // No Copy: a copied list is easily lost.
            expect(screen.queryByRole('button', { name: /copy/i })).not.toBeInTheDocument();
            // No way out but Download, then Finish.
            expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
            fireEvent.keyDown(dialog(), { key: 'Escape' });
            expect(onClose).not.toHaveBeenCalled();
            expect(screen.getByRole('button', { name: 'Finish' })).toBeDisabled();
            expect(dialog()).toHaveTextContent('Download your codes to finish.');

            await userEvent.click(screen.getByRole('button', { name: 'Download recovery codes' }));
            expect(onDownload).toHaveBeenCalledOnce();
            expect(screen.getByRole('button', { name: 'Finish' })).toBeDisabled();

            // The page says the server served the file.
            rerender(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" confirmed recoveryCodes={codes} onDownload={onDownload} downloaded onComplete={onComplete} onClose={onClose} />);
            expect(screen.getByRole('button', { name: 'Downloaded. Download again' })).toBeInTheDocument();
            expect(dialog()).not.toHaveTextContent('Download your codes to finish.');
            await userEvent.click(screen.getByRole('button', { name: 'Finish' }));
            expect(onComplete).toHaveBeenCalledOnce();
        });

        it('shows a download in progress, its error, and Finish saving', () => {
            const { rerender } = render(<MfaFactorSetupDialog {...totp} confirmed recoveryCodes={codes} downloading />);
            expect(screen.getByRole('button', { name: 'Downloading…' })).toBeDisabled();

            rerender(<MfaFactorSetupDialog {...totp} confirmed recoveryCodes={codes} downloadError="Couldn't download the codes." />);
            expect(screen.getByRole('alert')).toHaveTextContent("Couldn't download the codes.");

            rerender(<MfaFactorSetupDialog {...totp} confirmed recoveryCodes={codes} downloaded completing />);
            expect(screen.getByRole('button', { name: 'Finishing…' })).toBeDisabled();
        });

        it('ends with a short "added" step when there are no new codes', async () => {
            const onComplete = vi.fn();
            render(<MfaFactorSetupDialog {...sms} type="email" label="Email" sentTo="j***@example.com" confirmed onComplete={onComplete} />);

            expect(screen.getByRole('dialog', { name: 'Email added' })).toBeInTheDocument();
            await userEvent.click(screen.getByRole('button', { name: 'Done' }));
            expect(onComplete).toHaveBeenCalledOnce();
        });

        it('copies the setup key even where the Clipboard API is blocked', async () => {
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: () => Promise.reject(new Error('blocked')) } });
            const execCommand = vi.fn(() => true);
            Object.assign(document, { execCommand });
            render(<MfaFactorSetupDialog {...totp} secret="JBSWY3DPEHPK3PXP" />);

            await userEvent.click(screen.getByRole('button', { name: 'Copy' }));
            expect(execCommand).toHaveBeenCalledWith('copy');
            expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
        });
    });

    it('starts over each time it opens', async () => {
        const { rerender } = render(<MfaFactorSetupDialog {...totp} />);
        await userEvent.click(screen.getByRole('button', { name: 'Next' }));

        rerender(<MfaFactorSetupDialog {...totp} open={false} />);
        act(() => rerender(<MfaFactorSetupDialog {...totp} />));

        expect(screen.getByText('Authenticator app · Step 1 of 3')).toBeInTheDocument();
    });
});
