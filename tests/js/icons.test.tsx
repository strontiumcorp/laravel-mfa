import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import * as Icons from '../../stubs/inertia-react/components/icons';

const icons = Object.entries(Icons).filter(([name]) => name.startsWith('MfaIcon')) as [string, (p: Icons.MfaIconProps) => ReactElement][];

describe('icons', () => {
    it.each(icons)('%s is decorative by default and takes size and classes', (_, Icon) => {
        const { container } = render(<Icon size={20} className="text-red-600" />);
        const svg = container.querySelector('svg')!;

        expect(svg).toHaveAttribute('aria-hidden', 'true');
        expect(svg).toHaveAttribute('width', '20');
        expect(svg).toHaveClass('text-red-600');
    });

    it('is labelled when given a title', () => {
        const { getByRole } = render(<Icons.MfaIconCheck title="Done" />);

        expect(getByRole('img', { name: 'Done' })).toBeInTheDocument();
    });

    it('draws a different icon per factor type', () => {
        const markup = (['totp', 'email', 'sms'] as const).map((type) => render(<Icons.MfaFactorIcon type={type} />).container.innerHTML);

        expect(new Set(markup).size).toBe(3);
        expect(markup[0]).toBe(render(<Icons.MfaIconAuthenticator />).container.innerHTML);
    });
});
