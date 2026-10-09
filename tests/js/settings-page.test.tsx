import { render, screen } from '@testing-library/react';
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
};
const email = { id: 7, type: 'email' as const, type_label: 'Email', label: null, destination: 'j***@example.com', last_used_at: null };
const props = {
    factors: [email],
    pending: [],
    availableTypes: [
        { type: 'totp' as const, label: 'Authenticator app', recommended: true },
        { type: 'sms' as const, label: 'SMS', recommended: false },
    ],
    recoveryCodesRemaining: 9,
    recoveryCodes: null,
    retryAfter: null,
    mustEnroll: false,
    requiredTypes: [],
    status: null,
    urls,
};

beforeEach(() => inertia.requests.splice(0));

describe('settings page', () => {
    it('starts an authenticator app and an SMS method', async () => {
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Authenticator app (recommended)' }));
        await userEvent.click(screen.getByRole('button', { name: 'SMS' }));
        await userEvent.type(screen.getByLabelText('Phone number, with country code'), '+15555550100{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: urls.store, data: { type: 'totp' } },
            { method: 'post', url: urls.store, data: { type: 'sms', destination: '+15555550100' } },
        ]);
    });

    it('confirms and resends pending setups at their own URLs', async () => {
        const pendingTotp = { ...email, id: 8, type: 'totp' as const, type_label: 'Authenticator app', destination: null, secret: 'JBSWY3DP', qr_svg: '<svg/>' };
        const pendingSms = { ...email, id: 9, type: 'sms' as const, type_label: 'SMS', destination: '+*******0100' };
        render(<MfaSettings {...props} pending={[pendingTotp, pendingSms]} />);

        await userEvent.type(screen.getByRole('textbox', { name: 'Code from your authenticator app' }), '111111{Enter}');
        await userEvent.click(screen.getByRole('button', { name: 'Resend' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Verification code' }), '222222{Enter}');

        expect(inertia.requests).toEqual([
            { method: 'post', url: '/mfa/factors/8/confirm', data: { code: '111111' } },
            { method: 'post', url: '/mfa/factors/9/resend', data: {} },
            { method: 'post', url: '/mfa/factors/9/confirm', data: { code: '222222' } },
        ]);
    });

    it('removes a factor and regenerates recovery codes after confirming', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<MfaSettings {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Remove Email' }));
        await userEvent.click(screen.getByRole('button', { name: 'Regenerate' }));

        expect(inertia.requests).toEqual([
            { method: 'delete', url: '/mfa/factors/7', data: null },
            { method: 'post', url: urls.recoveryCodes, data: {} },
        ]);
    });

    it('tells an enforced user what they must add', () => {
        render(<MfaSettings {...props} mustEnroll requiredTypes={[{ type: 'totp', label: 'Authenticator app' }]} />);

        expect(screen.getByRole('note')).toHaveTextContent('with: Authenticator app.');
    });

    it('shows new recovery codes even before any factor is listed', () => {
        render(<MfaSettings {...props} factors={[]} recoveryCodes={['aaaaa-11111']} />);

        expect(screen.getByRole('listitem')).toHaveTextContent('aaaaa-11111');
    });
});
