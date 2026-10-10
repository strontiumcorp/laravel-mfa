import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

// The published components must work in any React app: they import React and
// ./icons (the one place the icon set lives) and nothing else (no Inertia, no
// Ziggy, no other MFA component). icons.tsx itself needs only React.
// Vitest runs from the repo root (vitest.config.ts).
const dir = join(process.cwd(), 'stubs/inertia-react/components');
const files = readdirSync(dir).filter((f) => f.endsWith('.tsx'));
const components = files.filter((f) => f !== 'icons.tsx');
const importsOf = (file: string) => {
    const source = readFileSync(join(dir, file), 'utf8');

    return [...source.matchAll(/^\s*(?:import|export)\b[^'"]*?from\s+['"]([^'"]+)['"]/gm), ...source.matchAll(/^\s*import\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]);
};

describe('published components', () => {
    it('are all here', () => {
        expect(files.sort()).toEqual([
            'add-factor-form.tsx',
            'api-key-notice.tsx',
            'challenge-form.tsx',
            'destination-setup.tsx',
            'enable-nudge.tsx',
            'factor-cards.tsx',
            'factor-list.tsx',
            'factor-setup-dialog.tsx',
            'icons.tsx',
            'password-confirm-form.tsx',
            'recovery-code-form.tsx',
            'recovery-codes-dialog.tsx',
            'recovery-codes-panel.tsx',
            'send-code-button.tsx',
            'settings-card.tsx',
            'totp-setup.tsx',
            'trusted-browsers-panel.tsx',
        ]);
    });

    it.each(components)('%s imports only React and ./icons', (file) => {
        const source = readFileSync(join(dir, file), 'utf8');

        expect(importsOf(file).every((m) => m === 'react' || m === './icons')).toBe(true);
        expect(source).not.toMatch(/\broute\(/);
        expect(source).toMatch(/^export default function Mfa\w+/m);
    });

    it('keep every icon in icons.tsx, which imports only React', () => {
        expect(importsOf('icons.tsx').every((m) => m === 'react')).toBe(true);

        for (const file of components) {
            expect(readFileSync(join(dir, file), 'utf8'), file).not.toMatch(/<svg\b/);
        }
    });
});
