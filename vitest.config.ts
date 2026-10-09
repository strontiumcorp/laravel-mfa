import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Tests for the published React components (stubs/inertia-react/components),
// each on its own through props and callbacks, and for the Inertia pages
// (stubs/inertia-react/pages) with @inertiajs/react mocked.
export default defineConfig({
    resolve: {
        // How the pages import the components once published.
        alias: { '@/components/vendor/laravel-mfa': fileURLToPath(new URL('./stubs/inertia-react/components', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['tests/js/**/*.test.tsx'],
        setupFiles: ['tests/js/setup.ts'],
    },
});
