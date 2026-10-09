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
        it('shows the recovery codes, and Complete only once they are copied', async () => {
            const user = userEvent.setup();
            const onComplete = vi.fn();
            const onClose = vi.fn();
            render(<MfaFactorSetupDialog {...sms} sentTo="+*******0100" confirmed recoveryCodes={codes} onComplete={onComplete} onClose={onClose} />);

            expect(screen.getByRole('dialog', { name: 'Save your recovery codes' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 3 of 3')).toBeInTheDocument();
            expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
            expect(screen.getByText(/Each of these is a separate code\. Use one when you can't use your other sign-in methods; each works once/)).toBeInTheDocument();
            // Shown once: no way out but Complete.
            expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
            fireEvent.keyDown(dialog(), { key: 'Escape' });
            expect(onClose).not.toHaveBeenCalled();

            const complete = screen.getByRole('button', { name: 'Complete' });
            expect(complete).toBeDisabled();
            await user.click(screen.getByRole('button', { name: 'Copy' }));
            expect(await navigator.clipboard.readText()).toBe('aaaaa-11111\nbbbbb-22222');
            await user.click(complete);
            expect(onComplete).toHaveBeenCalledOnce();
        });

        it('downloads the codes as a text file, which also unlocks Complete', async () => {
            const createObjectURL = vi.fn((_blob: Blob) => 'blob:codes');
            const revokeObjectURL = vi.fn();
            Object.assign(URL, { createObjectURL, revokeObjectURL });
            const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
                expect(this.download).toBe('acme-recovery-codes.txt');
                expect(this.isConnected).toBe(true);
            });
            render(<MfaFactorSetupDialog {...totp} confirmed recoveryCodes={codes} downloadName="acme-recovery-codes.txt" />);

            await userEvent.click(screen.getByRole('button', { name: 'Download' }));

            expect(click).toHaveBeenCalledOnce();
            expect(await createObjectURL.mock.calls[0][0].text()).toContain('aaaaa-11111\nbbbbb-22222');
            await waitFor(() => expect(revokeObjectURL).toHaveBeenCalledWith('blob:codes'), { timeout: 2000 });
            expect(screen.getByRole('button', { name: 'Complete' })).toBeEnabled();
        });

        describe('the downloaded file', () => {
            const file = { app: 'Artistly (staging)', slug: 'artistly-staging', account: 'mojahid@mail.com' };

            // Downloads with the given props at 2026-10-09 08:05 local time in Asia/Dhaka; returns the file's name and text.
            const download = async (props: Partial<MfaFactorSetupDialogProps>) => {
                vi.useFakeTimers({ toFake: ['Date'] });
                vi.setSystemTime(new Date(2026, 9, 9, 8, 5));
                const resolved = Intl.DateTimeFormat().resolvedOptions();
                vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({ ...resolved, timeZone: 'Asia/Dhaka' });
                const createObjectURL = vi.fn((_blob: Blob) => 'blob:codes');
                Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() });
                let name = '';
                vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
                    name = this.download;
                });
                render(<MfaFactorSetupDialog {...totp} confirmed recoveryCodes={codes} {...props} />);

                await userEvent.click(screen.getByRole('button', { name: 'Download' }));

                return { name, text: await createObjectURL.mock.calls[0][0].text() };
            };

            it('is named after the app, the account and the local date', async () => {
                expect((await download({ recoveryCodesFile: file })).name).toBe('artistly-staging-recovery-codes-mojahid@mail.com-2026-10-09.txt');
            });

            it('says whose codes they are and when they were downloaded', async () => {
                expect((await download({ recoveryCodesFile: file })).text).toBe(
                    'Artistly (staging) two-factor recovery codes\n' +
                        'Account: mojahid@mail.com\n' +
                        'Downloaded: 2026-10-09 08:05 (Asia/Dhaka)\n' +
                        '\n' +
                        'Each line below is one code. Each code works once. Getting new codes cancels these.\n' +
                        '\n' +
                        'aaaaa-11111\nbbbbb-22222\n',
                );
            });

            it('replaces characters file names cannot hold', async () => {
                const { name } = await download({ recoveryCodesFile: { ...file, account: 'a/b\\c:d*e?f"g<h>i|j\tk@x.com' } });

                expect(name).toBe('artistly-staging-recovery-codes-a-b-c-d-e-f-g-h-i-j-k@x.com-2026-10-09.txt');
            });

            it('works without the app and account', async () => {
                const { name, text } = await download({});

                expect(name).toBe('recovery-codes-2026-10-09.txt');
                expect(text).toBe('Two-factor recovery codes\nDownloaded: 2026-10-09 08:05 (Asia/Dhaka)\n\nEach line below is one code. Each code works once. Getting new codes cancels these.\n\naaaaa-11111\nbbbbb-22222\n');
            });

            it('takes an explicit downloadName over the derived one', async () => {
                expect((await download({ recoveryCodesFile: file, downloadName: 'acme.txt' })).name).toBe('acme.txt');
            });
        });

        it('ends with a short "added" step when there are no new codes', async () => {
            const onComplete = vi.fn();
            render(<MfaFactorSetupDialog {...sms} type="email" label="Email" sentTo="j***@example.com" confirmed onComplete={onComplete} />);

            expect(screen.getByRole('dialog', { name: 'Email added' })).toBeInTheDocument();
            await userEvent.click(screen.getByRole('button', { name: 'Done' }));
            expect(onComplete).toHaveBeenCalledOnce();
        });

        it('falls back when the Clipboard API is blocked, and says so when that fails too', async () => {
            Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: () => Promise.reject(new Error('blocked')) } });
            const execCommand = vi.fn(() => true);
            Object.assign(document, { execCommand });
            const { unmount } = render(<MfaFactorSetupDialog {...totp} confirmed recoveryCodes={codes} />);

            await userEvent.click(screen.getByRole('button', { name: 'Copy' }));
            expect(execCommand).toHaveBeenCalledWith('copy');
            expect(screen.getByRole('button', { name: 'Complete' })).toBeEnabled();
            unmount();

            execCommand.mockReturnValue(false);
            render(<MfaFactorSetupDialog {...totp} confirmed recoveryCodes={codes} />);
            await userEvent.click(screen.getByRole('button', { name: 'Copy' }));
            expect(screen.getByRole('alert')).toHaveTextContent("Couldn't copy here. Download the codes instead.");
            expect(screen.getByRole('button', { name: 'Complete' })).toBeDisabled();
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
