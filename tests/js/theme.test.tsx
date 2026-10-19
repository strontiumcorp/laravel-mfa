// @vitest-environment node
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// The stubs read their accent from `--mfa-*` variables, with the built-in look
// as the fallback (`var(--mfa-primary,#111827)`), so an app without the theme
// looks as before. The published theme sets them from the app's shadcn/ui tokens:
// one file per Tailwind major, since shadcn keeps colours as HSL channels on 3
// and as full colours on 4. Set under `.dark` too, so they follow the app's dark
// tokens wherever it puts the class.

const stubs = fileURLToPath(new URL('../../stubs/inertia-react', import.meta.url));

const sources = ['components', 'pages'].flatMap((dir) =>
    readdirSync(join(stubs, dir))
        .filter((file) => file.endsWith('.tsx'))
        .map((file) => ({ file: `${dir}/${file}`, source: readFileSync(join(stubs, dir, file), 'utf8') })),
);

/** Set inline by a component (enable-nudge's position), not by the theme. */
const inline = ['--mfa-nudge-x'];

const uses = sources
    .flatMap(({ file, source }) => [...source.matchAll(/var\((--mfa-[\w-]+)(,?)/g)].map(([, name, comma]) => ({ file, name, fallback: comma === ',' })))
    .filter(({ name }) => !inline.includes(name));

/** The `--mfa-*` declarations in the theme's one rule. */
const variables = (css: string) => {
    const block = css.match(/^:root,\s*\.dark\s*\{([^}]*)\}/m)?.[1] ?? '';

    return new Map([...block.matchAll(/^\s*(--mfa-[\w-]+):\s*([^;]+);/gm)].map(([, name, value]) => [name, value.trim()]));
};

const files = {
    'Tailwind 4': readFileSync(join(stubs, 'css/theme.css'), 'utf8'),
    'Tailwind 3': readFileSync(join(stubs, 'css/theme.tailwind-3.css'), 'utf8'),
};

describe.each(Object.entries(files))('the %s theme', (_, css) => {
    const set = variables(css);

    it('sets exactly the variables the stubs use, in one rule for :root and .dark', () => {
        expect(uses.length).toBeGreaterThan(0);
        expect([...set.keys()].sort()).toEqual([...new Set(uses.map((u) => u.name))].sort());
        expect(css.match(/\{/g)).toHaveLength(1);
    });

    it("takes each one from the app's shadcn/ui token of the same name", () => {
        for (const [name, value] of set) {
            expect(value).toContain(`var(${name.replace('--mfa-', '--')})`);
        }
    });
});

it('reads colours as HSL channels on Tailwind 3 and as full colours on 4', () => {
    for (const [name, value] of variables(files['Tailwind 3'])) {
        expect(value).toBe(`hsl(var(${name.replace('--mfa-', '--')}))`);
    }
    for (const [name, value] of variables(files['Tailwind 4'])) {
        expect(value).toBe(`var(${name.replace('--mfa-', '--')})`);
    }
});

it('gives every use in the stubs a fallback, so an app without the theme keeps the built-in look', () => {
    expect(uses.filter((u) => !u.fallback)).toEqual([]);
});

it('leaves no primary button or accent on the fixed grey', () => {
    const classStrings = sources.flatMap(({ file, source }) => [...source.matchAll(/(['"`])((?:(?!\1)[^\\\n])*)\1/g)].map(([, , s]) => ({ file, tokens: s.split(/\s+/) })));
    const fixed = classStrings.filter(
        ({ tokens }) =>
            (tokens.includes('bg-gray-900') && tokens.includes('text-white')) ||
            tokens.some((t) => /^(dark:)?(focus|checked|focus-visible):(bg|border|ring)-gray-(900|100)/.test(t)),
    );

    expect(fixed).toEqual([]);
});
