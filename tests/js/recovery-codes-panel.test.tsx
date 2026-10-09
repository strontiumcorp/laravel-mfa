import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaRecoveryCodesPanel from '../../stubs/inertia-react/components/recovery-codes-panel';

const codes = ['aaaaa-11111', 'bbbbb-22222'];

describe('MfaRecoveryCodesPanel', () => {
    it('shows how many codes are left', () => {
        render(<MfaRecoveryCodesPanel remaining={7} onRegenerate={() => {}} />);

        expect(screen.getByText(/7 remaining/)).toBeInTheDocument();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });

    it('shows new codes once', () => {
        render(<MfaRecoveryCodesPanel remaining={2} codes={codes} onRegenerate={() => {}} />);

        expect(screen.getByRole('heading')).toHaveTextContent('Save your recovery codes');
        expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
    });

    it('copies the codes', async () => {
        const user = userEvent.setup();
        render(<MfaRecoveryCodesPanel remaining={2} codes={codes} onRegenerate={() => {}} />);

        await user.click(screen.getByRole('button', { name: 'Copy all' }));

        expect(await navigator.clipboard.readText()).toBe('aaaaa-11111\nbbbbb-22222');
        expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
    });

    it('regenerates after confirming', async () => {
        const onRegenerate = vi.fn();
        const confirmRegenerate = vi.fn(() => true);
        render(<MfaRecoveryCodesPanel remaining={1} onRegenerate={onRegenerate} confirmRegenerate={confirmRegenerate} />);

        await userEvent.click(screen.getByRole('button', { name: 'Regenerate' }));

        expect(confirmRegenerate).toHaveBeenCalledOnce();
        expect(onRegenerate).toHaveBeenCalledOnce();
    });

    it('does not regenerate when the user cancels', async () => {
        const onRegenerate = vi.fn();
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        render(<MfaRecoveryCodesPanel remaining={1} onRegenerate={onRegenerate} />);

        await userEvent.click(screen.getByRole('button', { name: 'Regenerate' }));

        expect(window.confirm).toHaveBeenCalledWith('Generate new codes? Your old codes will stop working.');
        expect(onRegenerate).not.toHaveBeenCalled();
    });

    it('is disabled while regenerating', () => {
        render(<MfaRecoveryCodesPanel remaining={1} onRegenerate={() => {}} processing />);

        expect(screen.getByRole('button', { name: 'Regenerate' })).toBeDisabled();
    });
});
