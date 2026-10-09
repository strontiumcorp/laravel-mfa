// A stand-in for @inertiajs/react: records every request a page makes, so
// tests can assert the URL and payload without a server. Use it with
//     vi.mock('@inertiajs/react', async () => (await import('./inertia-mock')).inertia.module);
//
// Every request succeeds, unless a test queues errors for the next ones:
//     inertia.respondWith({ password_confirmation_required: '...' });
import { useRef, useState } from 'react';

export type Request = { method: string; url: string; data: unknown };

type Errors = Record<string, string>;
type Options = { onStart?: () => void; onSuccess?: () => void; onError?: (errors: Errors) => void; onFinish?: () => void };

export const inertia = (() => {
    const requests: Request[] = [];
    const responses: (Errors | null)[] = [];
    // What usePage().props returns.
    const pageProps: Record<string, unknown> = {};

    // Callbacks run in Inertia's order: start, then success or error, then finish.
    const send = (request: Request, options: Options = {}, setErrors?: (errors: Errors) => void) => {
        requests.push(request);
        options.onStart?.();
        const errors = responses.shift() ?? null;
        // Like Inertia's form helper: failures stay on the form until the next success or clearErrors().
        setErrors?.(errors ?? {});
        if (errors) options.onError?.(errors);
        else options.onSuccess?.();
        options.onFinish?.();
    };

    const useForm = () => {
        const transform = useRef((data: unknown) => data);
        const [errors, setErrors] = useState<Errors>({});
        const form = {
            processing: false,
            errors,
            transform: (callback: (data: unknown) => unknown) => {
                transform.current = callback;
            },
            post: (url: string, options?: Options) => send({ method: 'post', url, data: transform.current({}) }, options, setErrors),
            clearErrors: () => setErrors({}),
        };

        return form;
    };

    return {
        requests,
        pageProps,
        /** Answer the next requests (in order) with these validation errors; null = success. */
        respondWith: (...errors: (Errors | null)[]) => responses.push(...errors),
        reset: () => {
            requests.splice(0);
            responses.splice(0);
            for (const key of Object.keys(pageProps)) delete pageProps[key];
        },
        module: {
            Head: () => null,
            usePage: () => ({ props: pageProps }),
            useForm,
            router: {
                post: (url: string, data: unknown = {}, options?: Options) => send({ method: 'post', url, data }, options),
                delete: (url: string, options?: Options) => send({ method: 'delete', url, data: null }, options),
            },
        },
    };
})();
