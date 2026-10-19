/// <reference types="vite/client" />
// "Brand": an app's shadcn/ui colour tokens, light and .dark, plus the published
// theme (stubs/inertia-react/css/theme.css), as an app that imports it would
// have them. "Built-in" loads neither: the look of an app without the theme.
import theme from '../stubs/inertia-react/css/theme.css?raw';

export const brands = {
    none: { label: 'Built-in colours', tokens: null },
    violet: {
        label: 'Brand · violet (like artistly)',
        tokens: { light: { primary: '#7042f3', 'primary-foreground': '#fafafa' }, dark: { primary: '#a387f8', 'primary-foreground': '#171717' } },
    },
    coral: {
        label: 'Brand · coral (like podcast-flow)',
        tokens: { light: { primary: '#ff7d67', 'primary-foreground': '#ffffff' }, dark: { primary: '#ff7d67', 'primary-foreground': '#ffffff' } },
    },
} as const;

export type Brand = keyof typeof brands;

export function applyBrand(brand: Brand) {
    const tokens = brands[brand]?.tokens;
    if (!tokens) return;

    const declarations = (values: Record<string, string>) => Object.entries(values).map(([name, value]) => `--${name}: ${value};`).join(' ');
    const style = document.createElement('style');
    style.textContent = `:root { ${declarations(tokens.light)} } .dark { ${declarations(tokens.dark)} }\n${theme}`;
    document.head.append(style);
}
