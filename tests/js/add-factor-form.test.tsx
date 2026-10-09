import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaAddFactorForm from '../../stubs/inertia-react/components/add-factor-form';

const types = [
    { type: 'totp' as const, label: 'Authenticator app' },
    { type: 'email' as const, label: 'Email' },
    { type: 'sms' as const, label: 'SMS' },
];

describe('MfaAddFactorForm', () => {
    it('starts an authenticator app at once', async () => {
        const onAdd = vi.fn();
        render(<MfaAddFactorForm types={types} onAdd={onAdd} />);

        await userEvent.click(screen.getByRole('button', { name: 'Authenticator app' }));

        expect(onAdd).toHaveBeenCalledWith('totp');
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('asks for a phone number for SMS', async () => {
        const onAdd = vi.fn();
        render(<MfaAddFactorForm types={types} onAdd={onAdd} />);

        await userEvent.click(screen.getByRole('button', { name: 'SMS' }));
        const input = screen.getByLabelText('Phone number, with country code');
        expect(input).toHaveAttribute('type', 'tel');
        expect(screen.getByRole('button', { name: 'Send code' })).toBeDisabled();

        await userEvent.type(input, ' +1 555 555 0100 ');
        await userEvent.click(screen.getByRole('button', { name: 'Send code' }));

        expect(onAdd).toHaveBeenCalledWith('sms', '+1 555 555 0100');
    });

    it('allows an empty email, meaning the account email', async () => {
        const onAdd = vi.fn();
        render(<MfaAddFactorForm types={types} onAdd={onAdd} />);

        await userEvent.click(screen.getByRole('button', { name: 'Email' }));
        expect(screen.getByLabelText(/Email address/)).toHaveAttribute('type', 'email');
        await userEvent.click(screen.getByRole('button', { name: 'Send code' }));

        expect(onAdd).toHaveBeenCalledWith('email', '');
    });

    it('cancels', async () => {
        render(<MfaAddFactorForm types={types} onAdd={() => {}} />);

        await userEvent.click(screen.getByRole('button', { name: 'Email' }));
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('closes after a successful submission', async () => {
        const { rerender } = render(<MfaAddFactorForm types={types} onAdd={() => {}} />);
        await userEvent.click(screen.getByRole('button', { name: 'Email' }));

        rerender(<MfaAddFactorForm types={types} onAdd={() => {}} processing />);
        expect(screen.getByRole('button', { name: 'Send code' })).toBeDisabled();
        rerender(<MfaAddFactorForm types={types} onAdd={() => {}} />);

        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('stays open with the error after a failed submission', async () => {
        const { rerender } = render(<MfaAddFactorForm types={types} onAdd={() => {}} />);
        await userEvent.click(screen.getByRole('button', { name: 'SMS' }));
        await userEvent.type(screen.getByRole('textbox'), '+1 555');

        rerender(<MfaAddFactorForm types={types} onAdd={() => {}} processing />);
        rerender(<MfaAddFactorForm types={types} onAdd={() => {}} error="This phone number can't be used." />);

        expect(screen.getByRole('textbox')).toHaveValue('+1 555');
        expect(screen.getByRole('alert')).toHaveTextContent("This phone number can't be used.");
    });

    it('badges the recommended types and lists them first', () => {
        render(
            <MfaAddFactorForm
                types={[
                    { type: 'email', label: 'Email' },
                    { type: 'sms', label: 'SMS', recommended: false },
                    { type: 'totp', label: 'Authenticator app', recommended: true },
                ]}
                onAdd={() => {}}
            />,
        );
        const buttons = screen.getAllByRole('button');

        expect(buttons.map((b) => b.textContent)).toEqual(['Authenticator appRecommended', 'Email', 'SMS']);
        expect(buttons[0]).toHaveAccessibleName('Authenticator app (recommended)');
        expect(screen.getAllByText('Recommended')).toHaveLength(1);
    });

    it('offers only the given types, and nothing when there are none', () => {
        const { rerender, container } = render(<MfaAddFactorForm types={[types[0]]} onAdd={() => {}} />);
        expect(screen.getAllByRole('button')).toHaveLength(1);

        rerender(<MfaAddFactorForm types={[]} onAdd={() => {}} />);
        expect(container).toBeEmptyDOMElement();
    });
});
