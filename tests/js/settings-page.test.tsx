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
    downloadRecoveryCodes: '/mfa/recovery-codes/download',
    finishRecoveryCodes: '/mfa/recovery-codes/finish',
    discardRecoveryCodes: '/mfa/recovery-codes/pending',
    confirmPassword: '/mfa/confirm-password',
    sendEnrollmentCode: '/mfa/enrollment-verification/send',
    verifyEnrollmentCode: '/mfa/enrollment-verification',
    forgetTrustedBrowser: '/mfa/trusted-browsers/__ID__',
    forgetTrustedBrowsers: '/mfa/trusted-browsers',
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
    recoveryCodesFile: { app: 'Acme', slug: 'acme', account: 'jane@example.com' },
    pendingRecoveryCodes: null,
    retryAfter: null,
    passwordRetryAfter: null,
    passwordConfirmationRequired: false,
    enrollmentVerification: null,
    mustEnroll: false,
    requiredTypes: [],
    nudge: null,
    trustedBrowsers: null,
    status: null,
    urls,
};

beforeEach(() => inertia.reset());
afterEach(() => vi.restoreAllMocks());

/**
 * The server's file download: fetch answers `status` (with a file name), and
 * the saved file's name and the URL asked for are recorded.
 */
function serveDownload(status = 200) {
    const saved = { url: '', name: '' };
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            saved.url = url;
            return new Response(status === 200 ? 'aaaaa-11111\n' : '', {
                status,
                headers: { 'Content-Disposition': 'attachment; filename="acme-recovery-codes-jane@example.com-2026-10-09.txt"' },
            });
        }),
    );
    Object.assign(URL, { createObjectURL: () => 'blob:codes', revokeObjectURL: () => {} });
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
        saved.name = this.download;
    });

    return saved;
}

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

    it('removes a factor after confirming inside its card', async () => {
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        expect(inertia.requests).toEqual([]);
        await userEvent.click(within(screen.getByRole('group', { name: 'Remove Email?' })).getByRole('button', { name: 'Remove' }));

        expect(inertia.requests).toEqual([{ method: 'delete', url: '/mfa/factors/7', data: null }]);
    });

    it('makes new recovery codes in a dialog: asks, then Download from the server, then Finish', async () => {
        const codes = ['ccccc-33333', 'ddddd-44444'];
        const download = serveDownload();
        const user = userEvent.setup();
        const { rerender } = render(<MfaSettings {...props} />);

        await user.click(screen.getByRole('button', { name: 'New codes' }));
        const ask = screen.getByRole('dialog', { name: 'Generate new recovery codes?' });
        expect(ask).toHaveTextContent('Your 9 unused codes stop working once');
        expect(inertia.requests).toEqual([]);

        await user.click(within(ask).getByRole('button', { name: 'Generate new codes' }));
        expect(inertia.requests).toEqual([{ method: 'post', url: urls.recoveryCodes, data: {} }]);
        // The server keeps them waiting in the session (the mock keeps props as given).
        rerender(<MfaSettings {...props} pendingRecoveryCodes={{ codes, downloaded: false, factor: null }} />);
        const dialog = screen.getByRole('dialog', { name: 'Save your new recovery codes' });
        expect(within(dialog).getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
        expect(within(dialog).queryByRole('button', { name: /copy/i })).not.toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Finish' })).toBeDisabled();

        await user.click(within(dialog).getByRole('button', { name: 'Download recovery codes' }));
        expect(download.url).toMatch(/^\/mfa\/recovery-codes\/download\?tz=/);
        expect(download.name).toBe('acme-recovery-codes-jane@example.com-2026-10-09.txt');
        await user.click(within(dialog).getByRole('button', { name: 'Finish' }));

        expect(inertia.requests.at(-1)).toEqual({ method: 'post', url: urls.finishRecoveryCodes, data: {} });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('tells an enforced user what they must add', () => {
        render(<MfaSettings {...props} mustEnroll requiredTypes={[{ type: 'totp', label: 'Authenticator app' }]} />);

        expect(screen.getByRole('note')).toHaveTextContent('Your account needs: Authenticator app');
        expect(screen.getByText('Required', { selector: 'header span' })).toBeInTheDocument();
    });

    it('shows a new set waiting in the session again after a reload, and drops it when closed', async () => {
        render(<MfaSettings {...props} pendingRecoveryCodes={{ codes: ['aaaaa-11111'], downloaded: true, factor: null }} />);

        const dialog = screen.getByRole('dialog', { name: 'Save your new recovery codes' });
        expect(within(dialog).getByRole('listitem')).toHaveTextContent('aaaaa-11111');
        // Downloaded before the reload: Finish is open.
        expect(within(dialog).getByRole('button', { name: 'Finish' })).toBeEnabled();

        await userEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
        expect(inertia.requests).toEqual([{ method: 'delete', url: urls.discardRecoveryCodes, data: null }]);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
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

    it('retries removing a factor and making new codes after the password, without asking "are you sure" again', async () => {
        const { rerender } = render(<MfaSettings {...props} />);

        inertia.respondWith(passwordRequired);
        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        await userEvent.click(within(screen.getByRole('group', { name: 'Remove Email?' })).getByRole('button', { name: 'Remove' }));
        const emailCard = screen.getByRole('heading', { name: 'Email' }).closest('article') as HTMLElement;
        expect(within(emailCard).getByLabelText('Password')).toBeInTheDocument();
        expect(screen.queryByRole('group', { name: 'Remove Email?' })).not.toBeInTheDocument();
        await userEvent.type(screen.getByLabelText('Password'), 'secret{Enter}');

        inertia.respondWith(passwordRequired);
        await userEvent.click(screen.getByRole('button', { name: 'New codes' }));
        await userEvent.click(screen.getByRole('button', { name: 'Generate new codes' }));
        const dialog = screen.getByRole('dialog', { name: 'Confirm your password' });
        await userEvent.type(within(dialog).getByLabelText('Password'), 'secret{Enter}');

        expect(inertia.requests.map((r) => `${r.method} ${r.url}`)).toEqual([
            'delete /mfa/factors/7',
            `post ${urls.confirmPassword}`,
            'delete /mfa/factors/7',
            `post ${urls.recoveryCodes}`,
            `post ${urls.confirmPassword}`,
            `post ${urls.recoveryCodes}`,
        ]);
        rerender(<MfaSettings {...props} pendingRecoveryCodes={{ codes: ['ccccc-33333'], downloaded: false, factor: null }} />);
        expect(screen.getByRole('dialog', { name: 'Save your new recovery codes' })).toHaveTextContent('ccccc-33333');
    });

    it('drops new codes when the password is cancelled', async () => {
        render(<MfaSettings {...props} />);

        inertia.respondWith(passwordRequired);
        await userEvent.click(screen.getByRole('button', { name: 'New codes' }));
        await userEvent.click(screen.getByRole('button', { name: 'Generate new codes' }));
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(inertia.requests.map((r) => r.url)).toEqual([urls.recoveryCodes]);

        // Opened again, it starts at the question.
        await userEvent.click(screen.getByRole('button', { name: 'New codes' }));
        expect(screen.getByRole('dialog', { name: 'Generate new recovery codes?' })).toBeInTheDocument();
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
        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        await userEvent.click(within(screen.getByRole('group', { name: 'Remove Email?' })).getByRole('button', { name: 'Remove' }));
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

    it('keeps a first method\'s setup open on its codes until they are downloaded and finished', async () => {
        const pendingTotp = { ...email, id: 8, type: 'totp' as const, type_label: 'Authenticator app', destination: null, secret: 'JBSWY3DP', qr_svg: '<svg/>' };
        const codes = ['aaaaa-11111', 'bbbbb-22222'];
        serveDownload();
        const user = userEvent.setup();
        const { rerender } = render(<MfaSettings {...props} factors={[]} pending={[pendingTotp]} />);

        await user.click(screen.getByRole('button', { name: 'Next' }));
        await user.type(screen.getByRole('textbox', { name: 'Code from your authenticator app' }), '123456{Enter}');
        // Verified: the server keeps the method off and its codes waiting.
        rerender(<MfaSettings {...props} factors={[]} pending={[]} pendingRecoveryCodes={{ codes, downloaded: false, factor: { id: 8, type: 'totp' } }} />);

        const dialog = screen.getByRole('dialog', { name: 'Save your recovery codes' });
        expect(within(dialog).getAllByRole('listitem').map((li) => li.textContent)).toEqual(codes);
        expect(screen.getAllByRole('list', { name: 'Recovery codes' })).toHaveLength(1);
        expect(within(dialog).queryByRole('button', { name: /copy/i })).not.toBeInTheDocument();
        expect(within(dialog).queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Finish' })).toBeDisabled();
        expect(dialog).toHaveTextContent('Download your codes to finish.');

        await user.click(within(dialog).getByRole('button', { name: 'Download recovery codes' }));
        expect(within(dialog).getByRole('button', { name: 'Downloaded. Download again' })).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Finish' }));

        expect(inertia.requests.map((r) => `${r.method} ${r.url}`)).toEqual(['post /mfa/factors/8/confirm', `post ${urls.finishRecoveryCodes}`]);
        rerender(<MfaSettings {...props} factors={[{ ...pendingTotp, confirmed_at: '2026-10-10T10:00:00+00:00' }]} pending={[]} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('reopens a first method on its codes after a reload, wherever the user left it', () => {
        render(<MfaSettings {...props} factors={[]} pendingRecoveryCodes={{ codes: ['aaaaa-11111'], downloaded: false, factor: { id: 8, type: 'totp' } }} />);

        const dialog = screen.getByRole('dialog', { name: 'Save your recovery codes' });
        expect(dialog).toHaveTextContent('Authenticator app');
        expect(within(dialog).getByRole('listitem')).toHaveTextContent('aaaaa-11111');
    });

    it('says when the codes could not be downloaded, and starts over when they expired', async () => {
        const download = serveDownload(500);
        const user = userEvent.setup();
        render(<MfaSettings {...props} factors={[]} pendingRecoveryCodes={{ codes: ['aaaaa-11111'], downloaded: false, factor: { id: 8, type: 'totp' } }} />);

        await user.click(screen.getByRole('button', { name: 'Download recovery codes' }));
        expect(screen.getByRole('alert')).toHaveTextContent("Couldn't download the codes. Check your connection and try again.");
        expect(screen.getByRole('button', { name: 'Finish' })).toBeDisabled();
        expect(download.name).toBe('');

        serveDownload(404);
        await user.click(screen.getByRole('button', { name: 'Download recovery codes' }));
        expect(screen.getByRole('alert')).toHaveTextContent('These codes expired. Start again.');
        expect(inertia.requests).toEqual([{ method: 'reload', url: '', data: null }]);
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

    describe("confirming it's the account before the first method", () => {
        const check = { email: 'j***@example.com' };
        const verificationRequired = { enrollment_verification_required: "Confirm it's you before adding your first sign-in method." };
        const first = { ...props, factors: [], mustEnroll: true, enrollmentVerification: check };

        it('emails a code and checks it, then gets an authenticator app its key', async () => {
            render(<MfaSettings {...first} />);

            await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));
            expect(inertia.requests).toEqual([]);
            const dialog = screen.getByRole('dialog', { name: "Confirm it's you" });
            expect(dialog).toHaveTextContent('j***@example.com');
            await userEvent.click(within(dialog).getByRole('button', { name: 'Email me a code' }));
            await userEvent.type(within(dialog).getByRole('textbox', { name: 'Code from the email' }), '123456{Enter}');

            expect(inertia.requests).toEqual([
                { method: 'post', url: urls.sendEnrollmentCode, data: {} },
                { method: 'post', url: urls.verifyEnrollmentCode, data: { code: '123456' } },
                { method: 'post', url: urls.store, data: { type: 'totp' } },
            ]);
        });

        it('asks for the password first, then the check, then the setup', async () => {
            render(<MfaSettings {...first} passwordConfirmationRequired />);

            await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));
            expect(screen.getByText('Authenticator app · Step 1 of 5')).toBeInTheDocument();
            await userEvent.type(screen.getByLabelText('Password'), 'secret{Enter}');
            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
            await userEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
            await userEvent.type(screen.getByRole('textbox', { name: 'Code from the email' }), '123456{Enter}');

            expect(inertia.requests.map((r) => r.url)).toEqual([urls.confirmPassword, urls.sendEnrollmentCode, urls.verifyEnrollmentCode, urls.store]);
        });

        it('shows why a send or a code failed, and stays on the check', async () => {
            inertia.respondWith({ code: 'Please wait before requesting another code.' });
            const { rerender } = render(<MfaSettings {...first} />);

            await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
            await userEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
            expect(screen.getByRole('alert')).toHaveTextContent('Please wait before requesting another code.');
            expect(screen.queryByRole('textbox')).not.toBeInTheDocument();

            await userEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
            rerender(<MfaSettings {...first} retryAfter={60} />);
            expect(screen.getByRole('button', { name: 'Resend in 1:00' })).toBeDisabled();

            inertia.respondWith({ code: 'The provided code is invalid.' });
            await userEvent.type(screen.getByRole('textbox', { name: 'Code from the email' }), '000000{Enter}');
            expect(screen.getByRole('alert')).toHaveTextContent('The provided code is invalid.');
            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
            expect(inertia.requests.map((r) => r.url)).toEqual([urls.sendEnrollmentCode, urls.sendEnrollmentCode, urls.verifyEnrollmentCode]);
        });

        it('goes on to the number once checked', async () => {
            const { rerender } = render(<MfaSettings {...first} />);

            await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
            await userEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
            await userEvent.type(screen.getByRole('textbox', { name: 'Code from the email' }), '123456{Enter}');
            // The mock keeps props as given: the server's next props no longer ask.
            rerender(<MfaSettings {...first} enrollmentVerification={null} />);

            expect(screen.getByRole('dialog', { name: 'Add a phone number' })).toBeInTheDocument();
            expect(screen.getByText('SMS · Step 2 of 4')).toBeInTheDocument();
            expect(inertia.requests.map((r) => r.url)).toEqual([urls.sendEnrollmentCode, urls.verifyEnrollmentCode]);
        });

        it('says to ask an administrator when no code can be emailed', async () => {
            render(<MfaSettings {...first} enrollmentVerification={{ email: null }} />);

            await userEvent.click(screen.getByRole('button', { name: 'Set up Authenticator app' }));

            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toHaveTextContent('Ask an administrator for a setup link to add your first sign-in method.');
            expect(screen.queryByRole('button', { name: 'Email me a code' })).not.toBeInTheDocument();
            expect(inertia.requests).toEqual([]);
        });

        it('brings the check back when adding answers 423, then sends the same number again', async () => {
            inertia.respondWith(verificationRequired);
            const { rerender } = render(<MfaSettings {...props} factors={[]} />);

            await userEvent.click(screen.getByRole('button', { name: 'Set up SMS' }));
            await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100{Enter}');
            // The 423 comes back with fresh props that ask for the check.
            rerender(<MfaSettings {...props} factors={[]} enrollmentVerification={check} />);
            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
            await userEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
            await userEvent.type(screen.getByRole('textbox', { name: 'Code from the email' }), '123456{Enter}');

            expect(inertia.requests).toEqual([
                { method: 'post', url: urls.store, data: { type: 'sms', destination: '+15555550100' } },
                { method: 'post', url: urls.sendEnrollmentCode, data: {} },
                { method: 'post', url: urls.verifyEnrollmentCode, data: { code: '123456' } },
                { method: 'post', url: urls.store, data: { type: 'sms', destination: '+15555550100' } },
            ]);
        });

        it('brings the check back when confirming answers 423, then returns to the code', async () => {
            const pendingSms = { ...email, id: 9, type: 'sms' as const, type_label: 'SMS', destination: '+*******0100' };
            inertia.respondWith(verificationRequired);
            const { rerender } = render(<MfaSettings {...props} factors={[]} pending={[pendingSms]} />);

            await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '222222{Enter}');
            rerender(<MfaSettings {...props} factors={[]} pending={[pendingSms]} enrollmentVerification={check} />);
            expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
            await userEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
            await userEvent.type(screen.getByRole('textbox', { name: 'Code from the email' }), '123456{Enter}');
            rerender(<MfaSettings {...props} factors={[]} pending={[pendingSms]} />);

            expect(screen.getByRole('dialog', { name: 'Enter the code' })).toBeInTheDocument();
            expect(inertia.requests.map((r) => r.url)).toEqual(['/mfa/factors/9/confirm', urls.sendEnrollmentCode, urls.verifyEnrollmentCode]);
        });
    });

    it('shows the nudge copy as a notice when the server sends it', () => {
        const { rerender } = render(<MfaSettings {...props} factors={[]} nudge={{ title: 'Protect your account', body: 'Turn on two-factor sign-in now.' }} />);

        expect(screen.getByRole('note')).toHaveTextContent('Protect your account');
        expect(screen.getByRole('note')).toHaveTextContent('Turn on two-factor sign-in now.');

        rerender(<MfaSettings {...props} />);
        expect(screen.queryByRole('note')).not.toBeInTheDocument();
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

    describe('trusted browsers', () => {
        const browsers = [
            { id: 4, label: 'Chrome on Mac', created_at: '2026-10-01T10:00:00Z', last_used_at: null, expires_at: '2026-10-31T10:00:00Z', current: true },
            { id: 3, label: 'Firefox on Windows', created_at: '2026-09-20T10:00:00Z', last_used_at: null, expires_at: '2026-10-20T10:00:00Z', current: false },
        ];

        it('is hidden when the feature is off', () => {
            render(<MfaSettings {...props} />);

            expect(screen.queryByRole('heading', { name: 'Trusted browsers' })).not.toBeInTheDocument();
        });

        it('says when there are none', () => {
            render(<MfaSettings {...props} trustedBrowsers={[]} />);

            expect(screen.getByRole('heading', { name: 'Trusted browsers' })).toBeInTheDocument();
            expect(screen.getByText(/None yet\./)).toBeInTheDocument();
        });

        it('forgets one browser, then all, at their DELETE URLs', async () => {
            render(<MfaSettings {...props} trustedBrowsers={browsers} />);

            await userEvent.click(screen.getByRole('button', { name: 'Forget Firefox on Windows' }));
            await userEvent.click(screen.getByRole('button', { name: 'Forget all' }));
            await userEvent.click(within(screen.getByRole('group', { name: 'Forget all trusted browsers?' })).getByRole('button', { name: 'Forget all' }));

            expect(inertia.requests).toEqual([
                { method: 'delete', url: '/mfa/trusted-browsers/3', data: null },
                { method: 'delete', url: urls.forgetTrustedBrowsers, data: null },
            ]);
            // No password asked: the buttons are free again.
            expect(screen.getByRole('button', { name: 'Forget this browser' })).toBeEnabled();
        });
    });
});
