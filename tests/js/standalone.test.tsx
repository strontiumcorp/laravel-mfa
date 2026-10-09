import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

// The published components must work in any React app: React is their only
// import (no Inertia, no Ziggy, no other MFA component).
// Vitest runs from the repo root (vitest.config.ts).
const dir = join(process.cwd(), 'stubs/inertia-react/components');
const components = readdirSync(dir).filter((f) => f.endsWith('.tsx'));

describe('published components', () => {
    it('are all here', () => {
        expect(components.sort()).toEqual([
            'add-factor-form.tsx',
            'api-key-notice.tsx',
            'challenge-form.tsx',
            'destination-setup.tsx',
            'factor-list.tsx',
            'password-confirm-form.tsx',
            'recovery-code-form.tsx',
            'recovery-codes-panel.tsx',
            'send-code-button.tsx',
            'totp-setup.tsx',
        ]);
    });

    it.each(components)('%s imports only React', (file) => {
        const source = readFileSync(join(dir, file), 'utf8');
        const imports = [...source.matchAll(/^\s*(?:import|export)\b[^'"]*?from\s+['"]([^'"]+)['"]/gm), ...source.matchAll(/^\s*import\s+['"]([^'"]+)['"]/gm)].map((m) => m[1]);

        expect(imports.every((m) => m === 'react')).toBe(true);
        expect(source).not.toMatch(/\broute\(/);
        expect(source).toMatch(/^export default function Mfa\w+/m);
    });
});
