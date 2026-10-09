import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// `make preview`: the published pages and components (stubs/inertia-react)
// against a fake backend, reloading on every edit.
const stubs = (path: string) => fileURLToPath(new URL(`../stubs/inertia-react/${path}`, import.meta.url));

export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    resolve: {
        alias: {
            // How the pages import the components once published, and Inertia swapped for the fake.
            '@/components/vendor/laravel-mfa': stubs('components'),
            '@inertiajs/react': fileURLToPath(new URL('./inertia.tsx', import.meta.url)),
        },
    },
    server: {
        port: 5180,
        // Let the dev server read the stubs one level up.
        fs: { allow: [fileURLToPath(new URL('..', import.meta.url))] },
    },
});
