import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MfaSettings from '../../stubs/inertia-react/pages/settings';
import { inertia } from './inertia-mock';

vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);

const urls = {
    store: '/mfa/factors',
    confirm: '/mfa/factors/__ID__/confirm',
    resend: '/mfa/factors/__ID__/resend',
    destroy: '/mfa/factors/__ID__',
    recoveryCodes: '/mfa/recovery-codes',
    confirmPassword: '/mfa/confirm-password',
};
const passwordRequired = { password_confirmation_required: 'Please confirm your password to continue.' };
const email = { id: 7, type: 'email' as const, type_label: 'Email', label: null, destination: 'j***@example.com', last_used_at: null };
const props = {
    factors: [email],
    pending: [],
    availableTypes: [
        { type: 'totp' as const, label: 'Authenticator app', recommended: true },
        { type: 'sms' as const, label: 'SMS', recommended: false },
    ],
    recoveryCodesRemaining: 9,
    recoveryCodesTotal: 10,
    recoveryCodes: null,
    retryAfter: null,
    passwordRetryAfter: null,
    passwordConfirmationRequired: false,
    mustEnroll: false,
    requiredTypes: [],
    status: null,
    urls,
};

beforeEach(() => inertia.reset());

describe('settings page', () => {
    it('starts an authenticator app and an SMS method', async () => {
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));
        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.store, data: { type: 'totp' } },
            { method: 'post', url: urls.store, data: { type: 'sms', destination: '+15555550100' } },
        ]);
    });

    it('picks up a pending setup in the dialog: resends and confirms at its own URLs', async () => {
        const pendingSms = { ...email, id: 9, type: 'sms' as const, type_label: 'SMS', destination: '+*******0100' };
        render(<MfaSettings {...props} pending={[pendingSms]} />);

        const dialog = screen.getByRole('dialog', { name: 'Enter the code' });
        expect(dialog).toHaveTextContent('We sent a code to +*******0100.');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Resend code' }));
        await userEvent.type(within(dialog).getByRole('textbox', { name: 'Verification code' }), '222222{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: '/mfa/factors/9/resend', data: {} },
            { method: 'post', url: '/mfa/factors/9/confirm', data: { code: '222222' } },
        ]);
    });

    it('removes a factor and regenerates recovery codes after confirming', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        await userEvent.click(screen.getByRole('button', { name: 'New codes' }));

        expect(inertia.requests).toEqual([
            { method: 'delete', url: '/mfa/factors/7', data: null },
            { method: 'post', url: urls.recoveryCodes, data: {} },
        ]);
    });

    it('tells an enforced user what they must add', () => {
        render(<MfaSettings {...props} mustEnroll requiredTypes={[{ type: 'totp', label: 'Authenticator app' }]} />);

        expect(screen.getByRole('note')).toHaveTextContent('Your account needs: Authenticator app');
        expect(screen.getByText('Required', { selector: 'header span' })).toBeInTheDocument();
    });

    it('shows new recovery codes even before any factor is listed', () => {
        render(<MfaSettings {...props} factors={[]} recoveryCodes={['aaaaa-11111']} />);

        expect(screen.getByRole('listitem')).toHaveTextContent('aaaaa-11111');
    });

    it('asks for the password first in the dialog when a change needs it', async () => {
        render(<MfaSettings {...props} passwordConfirmationRequired />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        const dialog = screen.getByRole('dialog', { name: 'Confirm your password' });
        expect(within(dialog).getByText('SMS · Step 1 of 4')).toBeInTheDocument();
        await userEvent.type(within(dialog).getByLabelText('Password'), 'secret{Enter}');

        expect(screen.getByRole('dialog', { name: 'Add a phone number' })).toBeInTheDocument();
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.confirmPassword, data: { password: 'secret' } },
            { method: 'post', url: urls.store, data: { type: 'sms', destination: '+15555550100' } },
        ]);
    });

    it('asks for the password mid-way when the server does (it expired), then retries the same number', async () => {
        inertia.respondWith(passwordRequired);
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100{Enter}');
        expect(screen.getByRole('dialog', { name: 'Confirm your password' })).toBeInTheDocument();
        await userEvent.type(screen.getByLabelText('Password'), 'secret{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.store, data: { type: 'sms', destination: '+15555550100' } },
            { method: 'post', url: urls.confirmPassword, data: { password: 'secret' } },
            { method: 'post', url: urls.store, data: { type: 'sms', destination: '+15555550100' } },
        ]);
    });

    it('gets an authenticator app its key right away, or after the password', async () => {
        const { unmount } = render(<MfaSettings {...props} />);
        await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));
        expect(inertia.requests).toEqual([{ method: 'post', url: urls.store, data: { type: 'totp' } }]);
        expect(screen.getByRole('dialog', { name: 'Set up an authenticator app' })).toHaveTextContent('Getting your setup key');
        unmount();

        inertia.reset();
        render(<MfaSettings {...props} passwordConfirmationRequired />);
        await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));
        expect(inertia.requests).toEqual([]);
        await userEvent.type(screen.getByLabelText('Password'), 'secret{Enter}');
        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.confirmPassword, data: { password: 'secret' } },
            { method: 'post', url: urls.store, data: { type: 'totp' } },
        ]);
    });

    it('retries removing a factor and regenerating codes after the password, without asking "are you sure" again', async () => {
        const sure = vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<MfaSettings {...props} />);

        inertia.respondWith(passwordRequired);
        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        const emailCard = screen.getByRole('heading', { name: 'Email' }).closest('article') as HTMLElement;
        expect(within(emailCard).getByLabelText('Password')).toBeInTheDocument();
        await userEvent.type(screen.getByLabelText('Password'), 'secret{Enter}');

        inertia.respondWith(passwordRequired);
        await userEvent.click(screen.getByRole('button', { name: 'New codes' }));
        const recoveryCard = screen.getByRole('heading', { name: 'Recovery codes' }).closest('article') as HTMLElement;
        expect(within(recoveryCard).getByLabelText('Password')).toBeInTheDocument();
        await userEvent.type(screen.getByLabelText('Password'), 'secret{Enter}');

        expect(inertia.requests.map((r) => `${r.method} ${r.url}`)).toEqual([
            'delete /mfa/factors/7',
            `post ${urls.confirmPassword}`,
            'delete /mfa/factors/7',
            `post ${urls.recoveryCodes}`,
            `post ${urls.confirmPassword}`,
            `post ${urls.recoveryCodes}`,
        ]);
        expect(sure).toHaveBeenCalledTimes(2);
    });

    it('keeps asking after a wrong password, and drops the change on cancel', async () => {
        inertia.respondWith(passwordRequired, { password: 'The provided password is incorrect.' });
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));
        await userEvent.type(screen.getByLabelText('Password'), 'wrong{Enter}');
        expect(screen.getByRole('heading', { name: 'Confirm your password' })).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('heading', { name: 'Confirm your password' })).not.toBeInTheDocument();
        expect(inertia.requests.map((r) => r.url)).toEqual([urls.store, urls.confirmPassword]);
    });

    it('does not ask for the password on other errors', async () => {
        inertia.respondWith({ destination: "We can't send verification codes to this destination." });
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100{Enter}');

        expect(screen.queryByRole('heading', { name: 'Confirm your password' })).not.toBeInTheDocument();
    });

    it('gives the password prompt the password countdown, and the code setup only its own', async () => {
        const pendingSms = { ...email, id: 9, type: 'sms' as const, type_label: 'SMS', destination: '+*******0100' };
        render(<MfaSettings {...props} pending={[pendingSms]} passwordRetryAfter={60} />);

        expect(within(screen.getByRole('dialog')).getByRole('button', { name: 'Resend code' })).toBeEnabled();
        await userEvent.click(screen.getByRole('button', { name: 'Close' }));

        inertia.respondWith(passwordRequired);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        expect(screen.getByRole('button', { name: 'Try again in 1:00' })).toBeDisabled();
    });

    it('sets up an authenticator app in a dialog, and leaves "Continue setup" in its card when closed', async () => {
        const pendingTotp = { ...email, id: 8, type: 'totp' as const, type_label: 'Authenticator app', destination: null, secret: 'JBSWY3DP', qr_svg: '<svg/>', otpauth_url: 'otpauth://totp/x' };
        render(<MfaSettings {...props} pending={[pendingTotp]} />);

        const dialog = screen.getByRole('dialog', { name: 'Set up an authenticator app' });
        expect(within(dialog).getByLabelText('Setup key')).toHaveTextContent('JBSW Y3DP');
        expect(within(dialog).getByRole('link', { name: 'Open in authenticator app' })).toHaveAttribute('href', 'otpauth://totp/x');
        expect(screen.getByText('On', { selector: 'header span' })).toBeInTheDocument();

        await userEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        const totp = screen.getByRole('heading', { name: 'Authenticator app' }).closest('article') as HTMLElement;
        await userEvent.click(within(totp).getByRole('button', { name: 'Continue setup' }));
        expect(screen.getByRole('dialog', { name: 'Set up an authenticator app' })).toBeInTheDocument();
    });

    it('shows the new recovery codes in the dialog only, until Complete', async () => {
        const pendingTotp = { ...email, id: 8, type: 'totp' as const, type_label: 'Authenticator app', destination: null, secret: 'JBSWY3DP', qr_svg: '<svg/>' };
        const codes = ['aaaaa-11111', 'bbbbb-22222'];
        // The mock keeps props as given; recoveryCodes stands for the flash after confirming.
        const user = userEvent.setup();
        render(<MfaSettings {...props} factors={[]} pending={[pendingTotp]} recoveryCodes={codes} />);

        await user.click(screen.getByRole('button', { name: 'Next' }));
        await user.type(screen.getByRole('textbox', { name: 'Code from your authenticator app' }), '123456{Enter}');

        const dialog = screen.getByRole('dialog', { name: 'Save your recovery codes' });
        expect(within(dialog).getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
        expect(screen.getAllByRole('list', { name: 'Recovery codes' })).toHaveLength(1);
        expect(within(dialog).getByRole('button', { name: 'Complete' })).toBeDisabled();

        await user.click(within(dialog).getByRole('button', { name: 'Copy' }));
        await user.click(within(dialog).getByRole('button', { name: 'Complete' }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Recovery codes' })).not.toBeInTheDocument();
    });

    it('starts each setup clean, without the last one\'s error', async () => {
        inertia.respondWith({ destination: "We can't send verification codes to this destination." });
        render(<MfaSettings {...props} factors={[]} availableTypes={[...props.availableTypes, { type: 'email', label: 'Email', recommended: false }]} />);

        await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+19005550100{Enter}');
        await userEvent.click(screen.getByRole('button', { name: 'Close' }));
        await userEvent.click(screen.getByRole('button', { name: 'Set up Email' }));

        expect(screen.getByRole('dialog', { name: 'Add an email address' })).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('reopens a pending setup once, on load, not one after another', async () => {
        const pendingSms = { ...email, id: 9, type: 'sms' as const, type_label: 'SMS', destination: '+*******0100' };
        const pendingTotp = { ...email, id: 8, type: 'totp' as const, type_label: 'Authenticator app', destination: null, secret: 'JBSWY3DP', qr_svg: '<svg/>' };
        render(<MfaSettings {...props} pending={[pendingSms, pendingTotp]} />);

        expect(screen.getByRole('dialog', { name: 'Enter the code' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Close' }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Continue setup' })).toHaveLength(2);
    });
});

