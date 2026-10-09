import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaFactorList, { type MfaListedFactor } from '../../stubs/inertia-react/components/factor-list';

const totp: MfaListedFactor = { id: 1, type: 'totp', type_label: 'Authenticator app', label: null, destination: null, last_used_at: null };
const sms: MfaListedFactor = { id: 2, type: 'sms', type_label: 'SMS', label: 'My phone', destination: '+*******0100', last_used_at: '2026-10-01T09:30:00+00:00' };

describe('MfaFactorList', () => {
    it('lists each factor with its destination and last use', () => {
        render(<MfaFactorList factors={[totp, sms]} onRemove={() => {}} />);
        const [first, second] = screen.getAllByRole('listitem');

        expect(first).toHaveTextContent('Authenticator app');
        expect(second).toHaveTextContent('My phone');
        expect(second).toHaveTextContent('+*******0100');
        expect(second).toHaveTextContent(`last used ${new Date(sms.last_used_at!).toLocaleDateString()}`);
        expect(first).not.toHaveTextContent('last used');
    });

    it('says when nothing is set up', () => {
        render(<MfaFactorList factors={[]} onRemove={() => {}} />);

        expect(screen.getByText('No methods set up yet.')).toBeInTheDocument();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });

    it('says when the account must enroll', () => {
        render(<MfaFactorList factors={[]} onRemove={() => {}} required />);

        expect(screen.getByRole('note')).toHaveTextContent('Your account requires two-factor authentication.');
    });

    it('names the required methods, even when the user has other methods', () => {
        render(<MfaFactorList factors={[sms]} onRemove={() => {}} required requiredLabels={['Authenticator app']} />);

        expect(screen.getByRole('note')).toHaveTextContent('Your account requires two-factor authentication with: Authenticator app. Set it up to continue.');
        expect(screen.getAllByRole('listitem')).toHaveLength(1);
    });

    it('lists several required methods as alternatives', () => {
        render(<MfaFactorList factors={[]} onRemove={() => {}} required requiredLabels={['Authenticator app', 'SMS']} />);

        expect(screen.getByRole('note')).toHaveTextContent('with: Authenticator app or SMS. Set one up to continue.');
    });

    it('removes a factor after confirming', async () => {
        const onRemove = vi.fn();
        const confirmRemove = vi.fn(() => true);
        render(<MfaFactorList factors={[totp, sms]} onRemove={onRemove} confirmRemove={confirmRemove} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove My phone' }));

        expect(confirmRemove).toHaveBeenCalledWith(sms);
        expect(onRemove).toHaveBeenCalledWith(sms);
    });

    it('keeps the factor when the user cancels', async () => {
        const onRemove = vi.fn();
        render(<MfaFactorList factors={[totp]} onRemove={onRemove} confirmRemove={() => false} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove Authenticator app' }));

        expect(onRemove).not.toHaveBeenCalled();
    });

    it('asks with window.confirm by default', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const onRemove = vi.fn();
        render(<MfaFactorList factors={[totp]} onRemove={onRemove} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove Authenticator app' }));

        expect(confirm).toHaveBeenCalledWith('Remove this method?');
        expect(onRemove).toHaveBeenCalledWith(totp);
    });

    it('disables the factor being removed', () => {
        render(<MfaFactorList factors={[totp, sms]} onRemove={() => {}} removingId={2} />);
        const [first, second] = screen.getAllByRole('listitem');

        expect(within(first).getByRole('button')).toBeEnabled();
        expect(within(second).getByRole('button')).toBeDisabled();
    });
});
