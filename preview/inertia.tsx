// A stand-in for @inertiajs/react in the preview (vite.config.ts aliases it):
// useForm and router send each visit to the fake backend after a short delay,
// then re-render the page with the new props, calling the same callbacks in
// the same order as Inertia (onStart, onSuccess or onError, onFinish).
import { type AnchorHTMLAttributes, type ReactNode, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { handle, type State } from './backend';

type Errors = Record<string, string>;
type Options = { onStart?: () => void; onSuccess?: (page?: unknown) => void; onError?: (errors: Errors) => void; onFinish?: () => void; preserveScroll?: boolean; preserveState?: boolean };

let state: State;
// Shared props are worked out from the state, like a server would on every visit.
let shared: (s: State) => Record<string, unknown> = () => ({});
const listeners = new Set<() => void>();
let version = 0;
const notify = () => {
    version++;
    listeners.forEach((l) => l());
};

/** Toasts the backend asks for (e.g. "Sent code 123456"). */
export const toasts = { listeners: new Set<(text: string) => void>() };

export function boot(initial: State, sharedProps: (s: State) => Record<string, unknown> = () => ({})) {
    state = initial;
    shared = sharedProps;
    notify();
}

export function useBackendState(): State {
    useSyncExternalStore(
        (l) => {
            listeners.add(l);
            return () => listeners.delete(l);
        },
        () => version,
    );

    return state;
}

const delay = () => new Promise((r) => setTimeout(r, 450));

async function visit(method: 'post' | 'delete', url: string, data: Record<string, unknown>, options: Options = {}, form?: { setErrors: (e: Errors) => void; setProcessing: (p: boolean) => void }) {
    options.onStart?.();
    form?.setProcessing(true);
    await delay();
    const result = handle(state, method, url, data);
    notify();
    if (result.toast) toasts.listeners.forEach((l) => l(result.toast!));
    if (result.errors) {
        form?.setErrors(result.errors);
        options.onError?.(result.errors);
    } else {
        form?.setErrors({});
        options.onSuccess?.();
    }
    form?.setProcessing(false);
    options.onFinish?.();
}

export function useForm<T extends Record<string, unknown>>(initial: T) {
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const transform = useRef<(data: T) => Record<string, unknown>>((d) => d);

    return {
        data: initial,
        errors,
        processing,
        hasErrors: Object.keys(errors).length > 0,
        transform: (callback: (data: T) => Record<string, unknown>) => {
            transform.current = callback;
        },
        post: (url: string, options?: Options) => visit('post', url, transform.current(initial), options, { setErrors, setProcessing }),
        clearErrors: () => setErrors({}),
    };
}

export const router = {
    post: (url: string, data: Record<string, unknown> = {}, options?: Options) => visit('post', url, data, options),
    delete: (url: string, options?: Options) => visit('delete', url, {}, options),
};

export function usePage() {
    return { props: shared(state) };
}

export function Head({ title }: { title?: string; children?: ReactNode }) {
    useEffect(() => {
        if (title) document.title = `${title} · MFA preview`;
    }, [title]);

    return null;
}

export function Link({ href, children, ...rest }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; children?: ReactNode }) {
    return (
        <a href={href} onClick={(e) => e.preventDefault()} {...rest}>
            {children}
        </a>
    );
}
