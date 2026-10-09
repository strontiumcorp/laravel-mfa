import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaChallengeForm, { type MfaChallengeFactor } from '../../stubs/inertia-react/components/challenge-form';

const totp: MfaChallengeFactor = { id: 1, type: 'totp', type_label: 'Authenticator app', label: null, destination: null };
const email: MfaChallengeFactor = { id: 2, type: 'email', type_label: 'Email', label: 'Work email', destination: 'j***@example.com' };
const sms: MfaChallengeFactor = { id: 3, type: 'sms', type_label: 'SMS', label: null, destination: '+*******0100' };

type Props = Parameters<typeof MfaChallengeForm>[0];

const setup = (props: Partial<Props> = {}) => {
    const callbacks = { onSelectFactor: vi.fn(), onSubmit: vi.fn() };
    const view = render(<MfaChallengeForm factors={[totp, email]} selectedFactorId={1} {...callbacks} {...props} />);
    const rerender = (more: Partial<Props>) => view.rerender(<MfaChallengeForm factors={[totp, email]} selectedFactorId={1} {...callbacks} {...props} {...more} />);

    return { ...callbacks, ...view, rerender };
};

const codeInput = () => screen.getByRole('textbox', { name: 'Verification code' }) as HTMLInputElement;
const boxes = (container: HTMLElement) => [...container.querySelectorAll('[data-box]')].map((b) => b.textContent);

describe('MfaChallengeForm', () => {
    describe('one method at a time', () => {
        it.each([
            ['authenticator app', totp, 'Open your authenticator app', 'Enter the 6-digit code from your authenticator app.'],
            ['email', { ...email, code_sent: true }, 'Check your email', 'Enter the 6-digit code we sent to j***@example.com.'],
            ['SMS', { ...sms, code_sent: true }, 'Check your phone', 'Enter the 6-digit code we sent to +*******0100.'],
        ])('titles the %s method', (_, factor, title, description) => {
            setup({ factors: [factor], selectedFactorId: factor.id });

            expect(screen.getByRole('heading', { name: title })).toBeInTheDocument();
            expect(screen.getByText((_, el) => el?.tagName === 'P' && el.textContent === description)).toBeInTheDocument();
        });

        it('says a code is on its way until it is out', () => {
            const { container, rerender } = setup({ selectedFactorId: 2 });
            const description = () => container.querySelector('[aria-live="polite"]')!;

            expect(description()).toHaveTextContent("We're sending a code to j***@example.com.");
            rerender({ sent: true });
            expect(description()).toHaveTextContent('Enter the 6-digit code we sent to j***@example.com.');
        });

        it('says when the send failed', () => {
            setup({ selectedFactorId: 2, sendFailed: true });

            expect(screen.getByText((_, el) => el?.tagName === 'P' && el.textContent === "We couldn't send a code to j***@example.com.")).toBeInTheDocument();
        });

        it('says when the code that was out expired', () => {
            setup({ selectedFactorId: 2, expired: true });

            expect(screen.getByText((_, el) => el?.tagName === 'P' && el.textContent === 'The code we sent to j***@example.com has expired.')).toBeInTheDocument();
        });

        it('says a code was just used when a new one has to wait', () => {
            setup({ selectedFactorId: 2, waiting: true });

            expect(screen.getByText((_, el) => el?.tagName === 'P' && el.textContent === 'You recently used a code sent to j***@example.com.')).toBeInTheDocument();
        });

        it('uses the factor\'s code length', () => {
            const { container } = setup({ factors: [{ ...email, code_sent: true, code_length: 8 }], selectedFactorId: 2 });

            expect(boxes(container)).toHaveLength(8);
            expect(codeInput()).toHaveAttribute('maxlength', '8');
            expect(container.querySelector('[aria-live="polite"]')).toHaveTextContent('Enter the 8-digit code');
        });

        it('renders children under the code input', () => {
            setup({ children: <button type="button">Send code</button> });

            const send = screen.getByRole('button', { name: 'Send code' });
            expect(codeInput().compareDocumentPosition(send) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });
    });

    describe('the code input', () => {
        it('is one field the browser can fill from a text message', () => {
            const { container } = setup();

            expect(codeInput()).toHaveAttribute('autocomplete', 'one-time-code');
            expect(codeInput()).toHaveAttribute('inputmode', 'numeric');
            expect(codeInput()).toHaveAttribute('pattern', '\\d{6}');
            expect(codeInput()).toHaveFocus();
            expect(boxes(container)).toEqual(['', '', '', '', '', '']);
        });

        it('fills one box per digit and takes digits only', async () => {
            const { container, onSubmit } = setup();

            await userEvent.type(codeInput(), '12a 34-56');
            expect(codeInput()).toHaveValue('123456');
            expect(boxes(container)).toEqual(['1', '2', '3', '4', '5', '6']);
            await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

            expect(onSubmit).toHaveBeenCalledWith('123456');
        });

        it('fills every box from a pasted code, replacing what was typed', async () => {
            const { container } = setup();
            await userEvent.type(codeInput(), '99');

            fireEvent.paste(codeInput(), { clipboardData: { getData: () => ' 654-321 ' } });

            expect(codeInput()).toHaveValue('654321');
            expect(boxes(container)).toEqual(['6', '5', '4', '3', '2', '1']);
        });

        it('deletes the last digit with Backspace', async () => {
            const { container } = setup();

            await userEvent.type(codeInput(), '1234{Backspace}');

            expect(boxes(container)).toEqual(['1', '2', '3', '', '', '']);
        });

        it('marks the box being typed into', async () => {
            const { container } = setup();
            await userEvent.type(codeInput(), '12');

            const marked = [...container.querySelectorAll('[data-box]')].map((b) => b.className.includes('border-gray-900'));
            expect(marked).toEqual([false, false, true, false, false, false]);
        });

        it('needs every digit', async () => {
            setup();
            const verify = screen.getByRole('button', { name: 'Verify' });

            await userEvent.type(codeInput(), '12345');
            expect(verify).toBeDisabled();
            await userEvent.type(codeInput(), '67');
            expect(codeInput()).toHaveValue('123456');
            expect(verify).toBeEnabled();
        });

        it('submits with Enter', async () => {
            const { onSubmit } = setup();

            await userEvent.type(codeInput(), '654321{Enter}');

            expect(onSubmit).toHaveBeenCalledWith('654321');
        });

        it('does not submit a short code with Enter', async () => {
            const { onSubmit } = setup();

            await userEvent.type(codeInput(), '654{Enter}');

            expect(onSubmit).not.toHaveBeenCalled();
        });

        it('is disabled while verifying', async () => {
            setup({ processing: true });
            await userEvent.type(codeInput(), '123456');

            expect(screen.getByRole('button', { name: 'Verify' })).toBeDisabled();
        });

        it('shows the error and keeps the code, selected, after a failed attempt', async () => {
            const { rerender } = setup();
            const input = codeInput();
            await userEvent.type(input, '123456');

            rerender({ processing: true });
            rerender({ error: 'Invalid code.' });

            expect(screen.getByRole('alert')).toHaveTextContent('Invalid code.');
            expect(input).toHaveValue('123456');
            expect(input).toHaveAttribute('aria-invalid', 'true');
            // Kept so the user sees what they typed, and selected so typing replaces it.
            expect(input).toHaveFocus();
            expect([input.selectionStart, input.selectionEnd]).toEqual([0, 6]);
        });

        it('selects the code again after a second failure with the same message', async () => {
            const { rerender } = setup({ error: 'Invalid code.' });
            const input = codeInput();
            await userEvent.type(input, '123456');

            rerender({ processing: true, error: 'Invalid code.' });
            rerender({ error: 'Invalid code.' });

            expect(input).toHaveValue('123456');
            // Kept so the user sees what they typed, and selected so typing replaces it.
            expect(input).toHaveFocus();
            expect([input.selectionStart, input.selectionEnd]).toEqual([0, 6]);
        });

        it('keeps the input when there is an old error but no new attempt', async () => {
            setup({ error: 'Invalid code.' });

            await userEvent.type(codeInput(), '123456');

            expect(codeInput()).toHaveValue('123456');
            expect(codeInput()).not.toHaveAttribute('aria-invalid');
        });

        it('clears the input when the factor changes', async () => {
            const { rerender } = setup();
            await userEvent.type(codeInput(), '123456');

            rerender({ selectedFactorId: 2 });

            expect(codeInput()).toHaveValue('');
        });
    });

    describe('try another way', () => {
        it('lists every method and recovery codes, then shows the one picked', async () => {
            const { onSelectFactor } = setup({ factors: [totp, email, sms], onUseRecoveryCode: vi.fn() });

            await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));

            expect(screen.getByRole('heading', { name: 'Choose how to verify' })).toHaveFocus();
            const rows = within(screen.getByRole('list')).getAllByRole('button');
            expect(rows.map((r) => r.textContent)).toEqual([
                'Authenticator appCode from your app',
                'Emailj***@example.com',
                'Text message+*******0100',
                'Recovery codeOne of the codes you saved',
            ]);
            expect(rows[0]).toHaveAttribute('aria-current', 'true');

            await userEvent.click(rows[2]);

            expect(onSelectFactor).toHaveBeenCalledWith(3);
            expect(codeInput()).toHaveFocus();
        });

        it('goes back to the current method without choosing again', async () => {
            const { onSelectFactor } = setup();

            await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));
            await userEvent.click(screen.getByRole('button', { name: 'Back' }));

            expect(screen.getByRole('heading', { name: 'Open your authenticator app' })).toBeInTheDocument();
            expect(codeInput()).toHaveFocus();
            expect(onSelectFactor).not.toHaveBeenCalled();
        });

        it('does not choose the current method again', async () => {
            const { onSelectFactor } = setup();

            await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));
            await userEvent.click(screen.getByRole('button', { name: /^Authenticator app/ }));

            expect(onSelectFactor).not.toHaveBeenCalled();
            expect(codeInput()).toBeInTheDocument();
        });

        it('offers a recovery code', async () => {
            const onUseRecoveryCode = vi.fn();
            setup({ factors: [totp], onUseRecoveryCode });

            await userEvent.click(screen.getByRole('button', { name: 'Try another way' }));
            await userEvent.click(screen.getByRole('button', { name: /^Recovery code/ }));

            expect(onUseRecoveryCode).toHaveBeenCalledOnce();
        });

        it('is hidden with one method and no recovery codes', () => {
            setup({ factors: [totp] });

            expect(screen.queryByRole('button', { name: 'Try another way' })).not.toBeInTheDocument();
        });

        it('can open on the list', () => {
            setup({ initialView: 'methods' });

            expect(screen.getByRole('heading', { name: 'Choose how to verify' })).toHaveFocus();
        });
    });

    it('signs out only when given a callback', async () => {
        const onSignOut = vi.fn();
        const { rerender, onSubmit } = setup({ factors: [totp] });
        expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();

        rerender({ onSignOut });
        await userEvent.click(screen.getByRole('button', { name: 'Sign out' }));

        expect(onSignOut).toHaveBeenCalledOnce();
        expect(onSubmit).not.toHaveBeenCalled();
    });
});
