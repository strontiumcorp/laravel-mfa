import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaFactorCards, { type MfaCardFactor } from '../../stubs/inertia-react/components/factor-cards';

const types = [
    { type: 'email' as const, label: 'Email' },
    { type: 'totp' as const, label: 'Authenticator app', recommended: true },
    { type: 'sms' as const, label: 'SMS' },
];
const email: MfaCardFactor = {
    id: 7,
    type: 'email',
    type_label: 'Email',
    label: null,
    destination: 'j***@example.com',
    confirmed_at: '2026-10-05T10:00:00+00:00',
    last_used_at: null,
};
const card = (name: string) => screen.getByRole('heading', { name }).closest('article') as HTMLElement;
const noop = () => {};

describe('MfaFactorCards', () => {
    it('shows each active method with its details', () => {
        render(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} />);

        const active = card('Email');
        expect(active).toHaveTextContent('Active');
        expect(active).toHaveTextContent('Codes sent to j***@example.com');
        expect(within(active).getByText('Added').nextSibling).toHaveTextContent(new Date(email.confirmed_at!).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }));
        expect(within(active).getByText('Last used').nextSibling).toHaveTextContent('Never');
        // A type the user already has offers no second "Set up".
        expect(screen.queryByRole('button', { name: 'Set up Email' })).not.toBeInTheDocument();
    });

    it('asks inside the card before removing, and waits while removing', async () => {
        const onRemove = vi.fn();
        const totp: MfaCardFactor = { ...email, id: 8, type: 'totp', type_label: 'Authenticator app', destination: null };
        const { rerender } = render(<MfaFactorCards types={types} factors={[email, totp]} onAdd={noop} onRemove={onRemove} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        const ask = within(card('Email')).getByRole('group', { name: 'Remove Email?' });
        expect(ask).toHaveTextContent("Signing in won't ask for codes from it any more.");
        expect(within(ask).getByRole('button', { name: 'Cancel' })).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Remove Email' })).toBeDisabled();
        expect(onRemove).not.toHaveBeenCalled();

        await userEvent.click(within(ask).getByRole('button', { name: 'Remove' }));
        expect(onRemove).toHaveBeenCalledWith(email);

        rerender(<MfaFactorCards types={types} factors={[email, totp]} onAdd={noop} onRemove={onRemove} removingId={7} />);
        expect(within(card('Email')).getByRole('button', { name: 'Removing…' })).toBeDisabled();
        expect(within(card('Email')).getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });

    it('says when it removes the only method', async () => {
        render(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));

        expect(screen.getByRole('group', { name: 'Remove Email?' })).toHaveTextContent("It's your only method, so signing in won't ask for a code until you add one again.");
    });

    it('does not remove on Cancel or Escape, and puts the focus back on Remove', async () => {
        const onRemove = vi.fn();
        render(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={onRemove} />);
        const remove = screen.getByRole('button', { name: 'Remove Email' });

        await userEvent.click(remove);
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('group')).not.toBeInTheDocument();
        expect(remove).toHaveFocus();

        await userEvent.click(remove);
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('group')).not.toBeInTheDocument();
        expect(remove).toHaveFocus();
        expect(onRemove).not.toHaveBeenCalled();
    });

    it('swaps the question for the password prompt when the server asks for it', async () => {
        const { rerender } = render(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} />);
        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));

        rerender(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} passwordPrompt={{ at: { factor: 7 }, node: <p>password prompt</p> }} />);
        expect(screen.queryByRole('group')).not.toBeInTheDocument();
        expect(within(card('Email')).getByText('password prompt')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove Email' })).toBeDisabled();

        // Cancelling the password ends the removal: the question doesn't come back.
        rerender(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} />);
        expect(screen.queryByRole('group')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove Email' })).toBeEnabled();
    });

    it('lists the recommended method first, and starts an authenticator app at once', async () => {
        const onAdd = vi.fn();
        render(<MfaFactorCards types={types} factors={[]} onAdd={onAdd} onRemove={noop} />);

        expect(screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent)).toEqual(['Authenticator app', 'Email', 'SMS']);
        expect(card('Authenticator app')).toHaveTextContent('Recommended');
        expect(card('SMS')).toHaveTextContent('Not set up');

        await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));
        expect(onAdd).toHaveBeenCalledWith('totp');
    });

    it('asks for the phone number inside the SMS card', async () => {
        const onAdd = vi.fn();
        render(<MfaFactorCards types={types} factors={[]} onAdd={onAdd} onRemove={noop} />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        const sms = card('SMS');
        const send = within(sms).getByRole('button', { name: 'Send code' });
        expect(within(sms).getByRole('list', { name: 'Steps' })).toHaveTextContent('Phone number');
        expect(send).toBeDisabled();

        await userEvent.type(within(sms).getByLabelText('Phone number, with country code'), ' +15555550100 ');
        await userEvent.click(send);
        expect(onAdd).toHaveBeenCalledWith('sms', '+15555550100');
    });

    it('lets email go out with an empty address (the account email), and cancels', async () => {
        const onAdd = vi.fn();
        render(<MfaFactorCards types={types} factors={[]} onAdd={onAdd} onRemove={noop} />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up Email' }));
        await userEvent.click(screen.getByRole('button', { name: 'Send code' }));
        expect(onAdd).toHaveBeenCalledWith('email', '');

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('button', { name: 'Send code' })).not.toBeInTheDocument();
    });

    it('keeps the destination form, with what was typed, until the code went out', async () => {
        const { rerender } = render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} />);
        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100');

        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} adding />);
        expect(screen.getByRole('button', { name: 'Send code' })).toBeDisabled();
        // Refused (an error, or a password prompt): still open, number kept.
        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} error="We can't send verification codes to this destination." />);
        expect(within(card('SMS')).getByRole('alert')).toHaveTextContent("We can't send verification codes to this destination.");
        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} />);
        expect(screen.getByLabelText('Phone number, with country code')).toHaveValue('+15555550100');

        // Sent: the setup arrives and takes the form's place.
        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} setups={{ sms: <p>code entry</p> }} />);
        expect(screen.queryByRole('button', { name: 'Send code' })).not.toBeInTheDocument();
        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} />);
        expect(screen.queryByLabelText('Phone number, with country code')).not.toBeInTheDocument();
    });

    it('shows a password prompt inside the card where the change started', () => {
        const prompt = <p>password prompt</p>;
        const { rerender } = render(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} passwordPrompt={{ at: { factor: 7 }, node: prompt }} />);
        expect(within(card('Email')).getByText('password prompt')).toBeInTheDocument();
        expect(screen.getAllByText('password prompt')).toHaveLength(1);

        rerender(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} passwordPrompt={{ at: { type: 'sms' }, node: prompt }} />);
        expect(within(card('SMS')).getByText('password prompt')).toBeInTheDocument();
        expect(within(card('Email')).queryByText('password prompt')).not.toBeInTheDocument();
    });

    it('shows an error from adding an authenticator app under the list', () => {
        render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} error="This verification method is not available." />);

        expect(screen.getByRole('alert')).toHaveTextContent('This verification method is not available.');
    });

    it('renders a setup in progress inside its card', () => {
        render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} setups={{ sms: <p>code entry</p> }} />);

        const sms = card('SMS');
        expect(sms).toHaveTextContent('Setting up');
        expect(within(sms).getByText('code entry')).toBeInTheDocument();
        expect(within(sms).getByRole('list', { name: 'Steps' })).toHaveTextContent('Enter the code');
        expect(within(sms).queryByRole('button', { name: 'Set up SMS' })).not.toBeInTheDocument();
    });

    it('still shows a setup in progress for a type the user already has', () => {
        render(<MfaFactorCards types={types} factors={[email]} onAdd={noop} onRemove={noop} setups={{ email: <p>second email</p> }} />);

        expect(screen.getAllByRole('heading', { name: 'Email' })).toHaveLength(2);
        expect(screen.getByText('second email')).toBeInTheDocument();
    });

    it('tells an enforced user what they need, and which methods don\'t count', () => {
        render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} required requiredTypes={['totp']} />);

        expect(screen.getByRole('note')).toHaveTextContent('Your account needs: Authenticator app');
        expect(card('Authenticator app')).toHaveTextContent('Required');
        expect(card('Email')).toHaveTextContent("Doesn't meet your account's requirement on its own.");
        expect(card('Authenticator app')).not.toHaveTextContent("Doesn't meet");
    });

    it('names every required method, and grays out a method the account can no longer sign in with', () => {
        const totpTypes = types.filter((t) => t.type !== 'email' && t.type !== 'sms');
        render(<MfaFactorCards types={[...totpTypes, { type: 'sms', label: 'SMS' }]} factors={[email]} onAdd={noop} onRemove={noop} required requiredTypes={['totp', 'sms']} />);

        expect(screen.getByRole('note')).toHaveTextContent('Your account needs: Authenticator app and SMS');
        expect(card('Email')).toHaveTextContent('Not used for sign-in');
        expect(card('Email')).not.toHaveTextContent('Active');
        expect(within(card('Email')).getByRole('button', { name: 'Remove Email' })).toBeEnabled();
    });

    it('asks for any method when the requirement names none', () => {
        render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} required />);

        expect(screen.getByRole('note')).toHaveTextContent('Your account needs two-factor authentication');
        expect(screen.queryByText(/Doesn't meet/)).not.toBeInTheDocument();
    });

    it('shows the nudge notice to a user who isn\'t required to set one up', () => {
        const notice = { title: 'Protect your account', body: 'Turn on two-factor sign-in now.' };
        const { rerender } = render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} notice={notice} />);

        expect(screen.getByRole('note')).toHaveTextContent('Protect your account');
        expect(screen.getByRole('note')).toHaveTextContent('Turn on two-factor sign-in now.');
        expect(within(screen.getByRole('note')).queryByRole('link')).not.toBeInTheDocument();
        expect(within(screen.getByRole('note')).queryByRole('button')).not.toBeInTheDocument();

        // The requirement's own note wins.
        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} notice={notice} required />);
        expect(screen.getByRole('note')).toHaveTextContent('Your account needs two-factor authentication');
        expect(screen.getByRole('note')).not.toHaveTextContent('Protect your account');
    });

    it('keeps methods of a type that is no longer offered, so they can be removed', () => {
        render(<MfaFactorCards types={types.filter((t) => t.type !== 'email')} factors={[email]} onAdd={noop} onRemove={noop} />);

        expect(screen.getByRole('button', { name: 'Remove Email' })).toBeInTheDocument();
    });

    it('disables "Set up" while adding', () => {
        render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} adding />);

        expect(screen.getByRole('button', { name: 'Set up Authenticator app' })).toBeDisabled();
    });

    it('makes the password the only next step while it is asked in a card', async () => {
        const { rerender } = render(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} />);
        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100');

        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} passwordPrompt={{ at: { type: 'sms' }, node: <p>password prompt</p> }} />);

        expect(screen.getByRole('button', { name: 'Send code' })).toBeDisabled();
        expect(within(card('SMS')).queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();

        rerender(<MfaFactorCards types={types} factors={[]} onAdd={noop} onRemove={noop} />);
        expect(screen.getByRole('button', { name: 'Send code' })).toBeEnabled();
        expect(within(card('SMS')).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });
});
