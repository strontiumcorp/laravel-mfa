// A stand-in for @inertiajs/react: records every request a page makes, so
// tests can assert the URL and payload without a server. Use it with
//     vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);
export type Request = { method: string; url: string; data: unknown };

export const inertia = (() => {
    const requests: Request[] = [];

    const useForm = () => {
        let transform = (data: unknown) => data;
        const form = {
            processing: false,
            errors: {} as Record<string, string>,
            transform: (callback: (data: unknown) => unknown) => {
                transform = callback;
            },
            post: (url: string) => requests.push({ method: 'post', url, data: transform({}) }),
            clearErrors: () => {},
        };

        return form;
    };

    return {
        requests,
        module: {
            Head: () => null,
            useForm,
            router: {
                post: (url: string, data: unknown = {}) => requests.push({ method: 'post', url, data }),
                delete: (url: string) => requests.push({ method: 'delete', url, data: null }),
            },
        },
    };
})();
