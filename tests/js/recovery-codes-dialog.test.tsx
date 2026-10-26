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
        expect(dialog()).toHaveTextContent("Your 8 unused codes stop working once you've downloaded the new ones and pressed Finish. You'll get 10 new codes to download.");
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
        expect(dialog()).toHaveTextContent("You've used all your codes. You'll get a new set of codes to download.");
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

    it('shows the new codes with Download only, and Finish once they are downloaded', async () => {
        const onComplete = vi.fn();
        const onDownload = vi.fn();
        const user = userEvent.setup();
        const { rerender } = render(<MfaRecoveryCodesDialog {...base} codes={codes} onDownload={onDownload} onComplete={onComplete} />);

        expect(screen.getByRole('dialog', { name: 'Save your new recovery codes' })).toHaveTextContent('until then your old codes keep working');
        expect(within(dialog()).getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
        expect(screen.queryByRole('button', { name: /copy/i })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Finish' })).toBeDisabled();

        await user.click(screen.getByRole('button', { name: 'Download recovery codes' }));
        expect(onDownload).toHaveBeenCalledOnce();

        rerender(<MfaRecoveryCodesDialog {...base} codes={codes} onDownload={onDownload} downloaded onComplete={onComplete} />);
        await user.click(screen.getByRole('button', { name: 'Finish' }));
        expect(onComplete).toHaveBeenCalledOnce();
    });

    it('can be closed on the codes (the page drops the new set; the old one stays)', async () => {
        const onClose = vi.fn();
        const user = userEvent.setup();
        render(<MfaRecoveryCodesDialog {...base} codes={codes} onClose={onClose} />);

        await user.keyboard('{Escape}');
        await user.click(screen.getByRole('button', { name: 'Close' }));
        expect(onClose).toHaveBeenCalledTimes(2);
    });

    it('shows a download error and Finish saving', () => {
        const { rerender } = render(<MfaRecoveryCodesDialog {...base} codes={codes} downloadError="Couldn't download the codes." />);
        expect(screen.getByRole('alert')).toHaveTextContent("Couldn't download the codes.");

        rerender(<MfaRecoveryCodesDialog {...base} codes={codes} downloaded completing />);
        expect(screen.getByRole('button', { name: 'Finishing…' })).toBeDisabled();
    });
});
