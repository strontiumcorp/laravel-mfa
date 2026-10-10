import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaRecoveryCodesDialog, { type MfaRecoveryCodesDialogProps } from '../../stubs/inertia-react/components/recovery-codes-dialog';

const codes = ['aaaaa-11111', 'bbbbb-22222'];
const base: MfaRecoveryCodesDialogProps = { open: true, remaining: 8, total: 10, onGenerate: () => {}, onClose: () => {}, onComplete: () => {} };
const dialog = () => screen.getByRole('dialog');

describe('MfaRecoveryCodesDialog', () => {
    it('renders nothing while closed', () => {
        const { container } = render(<MfaRecoveryCodesDialog {...base} open={false} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('asks first, says what is lost, and starts on Cancel', async () => {
        const onGenerate = vi.fn();
        const { unmount } = render(<MfaRecoveryCodesDialog {...base} onGenerate={onGenerate} />);

        expect(screen.getByRole('dialog', { name: 'Generate new recovery codes?' })).toHaveAttribute('aria-modal', 'true');
        expect(dialog()).toHaveTextContent("Your 8 unused codes stop working as soon as the new ones are made. You'll get 10 new codes to copy or download.");
        expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus();
        expect(document.body.style.overflow).toBe('hidden');

        await userEvent.click(screen.getByRole('button', { name: 'Generate new codes' }));
        expect(onGenerate).toHaveBeenCalledOnce();

        unmount();
        expect(document.body.style.overflow).toBe('');
    });

    it('words a single code, and none left', () => {
        const { rerender } = render(<MfaRecoveryCodesDialog {...base} remaining={1} />);
        expect(dialog()).toHaveTextContent('Your 1 unused code stops working');

        rerender(<MfaRecoveryCodesDialog {...base} remaining={0} total={null} />);
        expect(dialog()).toHaveTextContent("You've used all your codes. You'll get a new set of codes to copy or download.");
    });

    it('waits while generating, and shows why it failed', () => {
        render(<MfaRecoveryCodesDialog {...base} processing error="Something went wrong." />);

        expect(screen.getByRole('button', { name: 'Generating…' })).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent('Something went wrong.');
    });

    it('closes on Cancel, ×, or Escape before the codes are made', async () => {
        const onClose = vi.fn();
        render(<MfaRecoveryCodesDialog {...base} onClose={onClose} />);

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await userEvent.click(screen.getByRole('button', { name: 'Close' }));
        await userEvent.keyboard('{Escape}');

        expect(onClose).toHaveBeenCalledTimes(3);
    });

    it('asks for the password when told to, and clears it after a wrong one', async () => {
        const onConfirmPassword = vi.fn();
        const { rerender } = render(<MfaRecoveryCodesDialog {...base} askPassword onConfirmPassword={onConfirmPassword} />);

        expect(screen.getByRole('dialog', { name: 'Confirm your password' })).toBeInTheDocument();
        const input = screen.getByLabelText('Password');
        expect(input).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        await userEvent.type(input, 'secret{Enter}');
        expect(onConfirmPassword).toHaveBeenCalledWith('secret');

        rerender(<MfaRecoveryCodesDialog {...base} askPassword onConfirmPassword={onConfirmPassword} passwordProcessing />);
        rerender(<MfaRecoveryCodesDialog {...base} askPassword onConfirmPassword={onConfirmPassword} passwordError="The password is incorrect." />);
        expect(input).toHaveValue('');
        expect(screen.getByRole('alert')).toHaveTextContent('The password is incorrect.');
    });

    it('counts down after too many wrong passwords', () => {
        render(<MfaRecoveryCodesDialog {...base} askPassword onConfirmPassword={() => {}} passwordRetryAfter={65} />);

        expect(screen.getByRole('button', { name: 'Try again in 1:05' })).toBeDisabled();
    });

    it('shows the new codes once, with no way out until they are copied', async () => {
        const onComplete = vi.fn();
        const onClose = vi.fn();
        const user = userEvent.setup();
        render(<MfaRecoveryCodesDialog {...base} codes={codes} onComplete={onComplete} onClose={onClose} />);

        expect(screen.getByRole('dialog', { name: 'Save your new recovery codes' })).toHaveTextContent('Your old codes no longer work.');
        expect(within(dialog()).getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
        expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
        const complete = screen.getByRole('button', { name: 'Complete' });
        expect(complete).toBeDisabled();
        await user.keyboard('{Escape}');
        expect(onClose).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Copy' }));
        expect(await navigator.clipboard.readText()).toBe('aaaaa-11111\nbbbbb-22222');
        await user.click(complete);
        expect(onComplete).toHaveBeenCalledOnce();
    });

    it('downloads the codes as a file named after the app and account', async () => {
        const createObjectURL = vi.fn((_blob: Blob) => 'blob:codes');
        Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() });
        let name = '';
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            name = this.download;
        });
        render(<MfaRecoveryCodesDialog {...base} codes={codes} recoveryCodesFile={{ app: 'Acme', slug: 'acme', account: 'jane@example.com' }} />);

        await userEvent.click(screen.getByRole('button', { name: 'Download' }));

        expect(name).toMatch(/^acme-recovery-codes-jane@example\.com-\d{4}-\d{2}-\d{2}\.txt$/);
        const text = await createObjectURL.mock.calls[0][0].text();
        expect(text).toContain('Acme two-factor recovery codes\nAccount: jane@example.com\n');
        expect(text).toContain('aaaaa-11111\nbbbbb-22222\n');
        expect(screen.getByRole('button', { name: 'Complete' })).toBeEnabled();
    });
});
