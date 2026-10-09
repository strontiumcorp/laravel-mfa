import { render, screen } from '@testing-library/react';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import MfaChallengeForm from '../../stubs/inertia-react/components/challenge-form';

// Apps style form controls in their base layer: @tailwindcss/forms gives every
// text-like <input> (and an <input> with no type) a border, a white background,
// padding and a blue focus ring; other apps have a global `input {}` rule. The
// published files must not pick any of that up, so each control states its own
// type and look. Vitest runs from the repo root (vitest.config.ts).
const root = join(process.cwd(), 'stubs/inertia-react');
const sources = ['components', 'pages'].flatMap((dir) =>
    readdirSync(join(root, dir))
        .filter((f) => f.endsWith('.tsx'))
        .map((f) => ({ file: `${dir}/${f}`, source: readFileSync(join(root, dir, f), 'utf8') })),
);

/** Each `<tag …>` in the JSX, read up to the `>` that closes it (skipping `=>` and `>` inside `{…}` or quotes). */
function tags(source: string, tag: string): string[] {
    const found: string[] = [];
    for (const match of source.matchAll(new RegExp(`<${tag}\\b`, 'g'))) {
        let depth = 0;
        let quote: string | null = null;
        let i = match.index! + match[0].length;
        for (; i < source.length; i++) {
            const c = source[i];
            if (quote) {
                if (c === quote) quote = null;
            } else if (c === '"' || c === "'" || c === '`') {
                quote = c;
            } else if (c === '{') {
                depth++;
            } else if (c === '}') {
                depth--;
            } else if (c === '>' && depth === 0) {
                break;
            }
        }
        found.push(source.slice(match.index, i + 1));
    }

    return found;
}

/** The tag with the file's class constants (`const FIELD = '…'`) it names written out. */
function expand(tag: string, source: string): string {
    const constants = new Map([...source.matchAll(/^const ([A-Z_]+)\s*=\s*([`'])([\s\S]*?)\2;/gm)].map((m) => [m[1], m[3]]));
    let out = tag;
    for (let round = 0; round < 3; round++) {
        out = out.replace(/\b([A-Z][A-Z_]+)\b/g, (name) => (constants.has(name) ? ` ${constants.get(name)} ` : name));
    }

    return out;
}

const inputs = sources.flatMap(({ file, source }) => tags(source, 'input').map((tag, i) => ({ name: `${file} #${i + 1}`, tag: expand(tag, source) })));
const buttons = sources.flatMap(({ file, source }) => tags(source, 'button').map((tag, i) => ({ name: `${file} #${i + 1}`, tag })));
const has = (tag: string, classes: string[]) => classes.filter((c) => !new RegExp(`(^|[\\s"'\`])${c.replace(/[/.:[\]]/g, '\\$&')}`).test(tag));

// What a visible text field must set itself: everything the forms plugin's base layer sets.
const FIELD = ['appearance-none', 'border', 'rounded-', 'px-', 'py-', 'bg-white', 'dark:bg-', 'text-gray-', 'dark:text-', 'placeholder:text-', 'shadow-none', 'focus:outline-none', 'focus:ring-', 'focus:border-', 'dark:focus:ring-', 'dark:focus:border-'];
// The challenge's invisible code input: nothing may draw a frame around the boxes.
const OVERLAY = ['appearance-none', 'border-0', 'p-0', 'bg-transparent', 'shadow-none', 'ring-0', 'focus:border-0', 'focus:shadow-none', 'focus:outline-none', 'focus:ring-0', 'focus:ring-offset-0'];

// Every class string that sets a colour also sets its dark-mode colour, or dark
// pages get e.g. red-600 text on gray-900. Only literal class strings are read:
// a template's `${…}` parts are dropped and their quoted strings checked on their own.
const COLOR = /(?<![\w:/-])(?:text|bg|border)-(?:(?:gray|red|amber|green|indigo|sky|violet)-\d{2,3}(?:\/\d+)?|white)(?![\w/-])/;
// The dialog's backdrop is a translucent near-black, right in both themes.
const BOTH_THEMES = ['bg-gray-950/50'];
// api-key-notice and settings-card are being changed on another branch; they join this check once it's merged.
const NOT_YET = ['components/api-key-notice.tsx', 'components/settings-card.tsx'];

function classStrings(source: string): string[] {
    const templates = [...source.matchAll(/`([^`]*)`/g)].map((m) => m[1]);
    const quoted = [...source.matchAll(/"([^"\n]*)"|'([^'\n]*)'/g)].map((m) => m[1] ?? m[2]);

    return [...templates.map((t) => t.replace(/\$\{[^}]*\}/g, ' ')), ...quoted];
}

const undarkened = sources
    .filter(({ file }) => !NOT_YET.includes(file))
    .flatMap(({ file, source }) =>
        classStrings(source)
            .filter((c) => COLOR.test(c.split(/\s+/).filter((k) => !BOTH_THEMES.includes(k)).join(' ')) && !/(^|\s)dark:/.test(c))
            .map((c) => `${file}: ${c}`),
    );

describe('dark mode', () => {
    it('gives every colour class a dark variant on the same element', () => {
        expect(undarkened).toEqual([]);
    });
});

describe('host form styles', () => {
    it('finds the controls it checks', () => {
        expect(inputs.length).toBeGreaterThanOrEqual(9);
        expect(buttons.length).toBeGreaterThan(20);
        expect(sources.some(({ source }) => /<(select|textarea)\b/.test(source))).toBe(false);
    });

    it.each(inputs)('$name has a type and sets its own look', ({ tag }) => {
        expect(tag).toMatch(/\stype=/);
        expect(has(tag, tag.includes('bg-transparent') ? OVERLAY : FIELD)).toEqual([]);
    });

    it.each(buttons)('$name has a type', ({ tag }) => {
        expect(tag).toMatch(/\stype=/);
    });

    it("resets everything a host could add on the challenge's invisible code input", () => {
        render(<MfaChallengeForm factors={[{ id: 1, type: 'totp', type_label: 'Authenticator app', label: null, destination: null }]} selectedFactorId={1} onSelectFactor={() => {}} onSubmit={() => {}} />);
        const input = screen.getByRole('textbox', { name: 'Verification code' });

        expect(input).toHaveAttribute('type', 'text');
        expect(input).toHaveAttribute('autocomplete', 'one-time-code');
        for (const c of OVERLAY) {
            expect(input).toHaveClass(c);
        }
    });
});
