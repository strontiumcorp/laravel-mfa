import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaRecoveryCodesPanel from '../../stubs/inertia-react/components/recovery-codes-panel';

describe('MfaRecoveryCodesPanel', () => {
    it('shows how many codes are left', () => {
        render(<MfaRecoveryCodesPanel remaining={7} onNewCodes={() => {}} />);

        expect(screen.getByText('7 left')).toBeInTheDocument();
        expect(screen.queryByRole('meter')).not.toBeInTheDocument();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });

    it('shows a meter out of the total when given', () => {
        render(<MfaRecoveryCodesPanel remaining={8} total={10} onNewCodes={() => {}} />);

        expect(screen.getByText('8 of 10 left')).toBeInTheDocument();
        expect(screen.getByRole('meter', { name: 'Recovery codes left' })).toHaveAttribute('aria-valuenow', '8');
    });

    it('warns when only a couple are left', () => {
        render(<MfaRecoveryCodesPanel remaining={2} total={10} onNewCodes={() => {}} />);

        expect(screen.getByText('2 of 10 left. Make new ones before you run out.')).toBeInTheDocument();
        expect(screen.queryByRole('meter')).not.toBeInTheDocument();
    });

    it('hands "New codes" to the page, which asks first', async () => {
        const onNewCodes = vi.fn();
        render(<MfaRecoveryCodesPanel remaining={1} onNewCodes={onNewCodes} />);

        await userEvent.click(screen.getByRole('button', { name: 'New codes' }));

        expect(onNewCodes).toHaveBeenCalledOnce();
    });

    it('is disabled while regenerating', () => {
        render(<MfaRecoveryCodesPanel remaining={1} onNewCodes={() => {}} processing />);

        expect(screen.getByRole('button', { name: 'New codes' })).toBeDisabled();
    });
});
