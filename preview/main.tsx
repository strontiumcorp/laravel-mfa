// The preview: `make preview`, then open the URL it prints. The shell picks a
// scenario, a theme and device widths; each frame is an iframe of this same
// page (?frame=…), so Tailwind's breakpoints (sm:, md:) see the frame's width.
import { type ReactNode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import MfaApiKeyNotice from '../stubs/inertia-react/components/api-key-notice';
import MfaSettingsCard from '../stubs/inertia-react/components/settings-card';
import MfaChallenge from '../stubs/inertia-react/pages/challenge';
import { mfaApiKeyNoticeProps, mfaSettingsCardProps, type MfaContext } from '../stubs/inertia-react/pages/mfa-context';
import MfaSettings from '../stubs/inertia-react/pages/settings';
import { CODE, PASSWORD, RECOVERY_CODE, challengeProps, settingsProps, type State } from './backend';
import { boot, Link, toasts, useBackendState } from './inertia';
import { scenarios } from './scenarios';

const DEVICES = { phone: 390, tablet: 768, desktop: 1280 } as const;
type Device = keyof typeof DEVICES | 'all';
type Theme = 'system' | 'light' | 'dark';

const params = new URLSearchParams(location.search);

function applyTheme(theme: Theme) {
    const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}

function contextFor(s: State): MfaContext {
    return {
        enabled: true,
        factors: s.enabledTypes,
        passwordConfirmation: s.requirePassword,
        user: { hasMfa: s.factors.length > 0, verified: true, mustEnroll: s.mustEnroll },
        urls: { settings: '/mfa/settings', challenge: '/mfa/challenge' },
    };
}

/** What one frame renders: the page, driven by the fake backend. */
function Frame({ page }: { page: string }) {
    const s = useBackendState();
    const [toast, setToast] = useState<string | null>(null);

    useEffect(() => {
        const show = (text: string) => {
            setToast(text);
            setTimeout(() => setToast((t) => (t === text ? null : t)), 3500);
        };
        toasts.listeners.add(show);
        return () => void toasts.listeners.delete(show);
    }, []);

    let content: ReactNode;
    if (page === 'challenge') {
        content = <MfaChallenge {...challengeProps(s)} />;
    } else if (page === 'account') {
        const mfa = contextFor(s);
        content = (
            <div className="mx-auto max-w-2xl space-y-6 px-4 py-10">
                <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">Account settings</h1>
                <MfaSettingsCard {...mfaSettingsCardProps(mfa)} renderLink={(link) => <Link {...link} />} />
                <MfaApiKeyNotice {...mfaApiKeyNoticeProps(mfa)} />
            </div>
        );
    } else {
        content = <MfaSettings {...settingsProps(s)} />;
    }

    return (
        <div className="min-h-screen bg-gray-50 dark:bg-gray-950">
            {content}
            {toast && (
                <div role="status" className="fixed inset-x-0 bottom-4 mx-auto w-fit max-w-[90%] rounded-lg bg-gray-900 px-4 py-2 text-sm text-white shadow-lg dark:bg-gray-100 dark:text-gray-900">
                    {toast}
                </div>
            )}
        </div>
    );
}

function Shell() {
    const [scenario, setScenario] = useState(params.get('scenario') ?? scenarios[0].id);
    const [device, setDevice] = useState<Device>((params.get('device') as Device) ?? 'all');
    const [theme, setTheme] = useState<Theme>((params.get('theme') as Theme) ?? 'system');
    const [reset, setReset] = useState(0);

    useEffect(() => {
        applyTheme(theme);
        history.replaceState(null, '', `?scenario=${scenario}&device=${device}&theme=${theme}`);
    }, [scenario, device, theme]);

    const widths = device === 'all' ? Object.entries(DEVICES) : [[device, DEVICES[device]] as const];
    const src = `?frame=1&scenario=${scenario}&theme=${theme}`;
    const select = 'rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900';

    return (
        <div className="flex h-screen flex-col bg-gray-200 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
            <header className="flex flex-wrap items-center gap-3 border-b border-gray-300 bg-white px-4 py-2 dark:border-gray-800 dark:bg-gray-950">
                <strong className="text-sm">laravel-mfa preview</strong>
                <select aria-label="Scenario" value={scenario} onChange={(e) => setScenario(e.target.value)} className={select}>
                    {scenarios.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.title}
                        </option>
                    ))}
                </select>
                <select aria-label="Device" value={device} onChange={(e) => setDevice(e.target.value as Device)} className={select}>
                    <option value="all">All widths</option>
                    <option value="phone">Phone · 390</option>
                    <option value="tablet">Tablet · 768</option>
                    <option value="desktop">Desktop · 1280</option>
                </select>
                <select aria-label="Theme" value={theme} onChange={(e) => setTheme(e.target.value as Theme)} className={select}>
                    <option value="system">System theme</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </select>
                <button type="button" onClick={() => setReset((r) => r + 1)} className={select}>
                    Reset
                </button>
                <span className="text-xs text-gray-500 dark:text-gray-400">
                    Code <code>{CODE}</code> · password <code>{PASSWORD}</code> · recovery code <code>{RECOVERY_CODE}</code>
                </span>
            </header>
            <main className="flex grow gap-6 overflow-auto p-6">
                {widths.map(([name, width]) => (
                    <figure key={`${name}-${reset}`} className="m-0 flex shrink-0 flex-col gap-2">
                        <figcaption className="text-xs text-gray-600 dark:text-gray-400">
                            {name} · {width}px
                        </figcaption>
                        <iframe title={`${name} preview`} src={src} allow="clipboard-write" style={{ width }} className="h-full min-h-[860px] rounded-lg border border-gray-300 bg-white shadow-sm dark:border-gray-700" />
                    </figure>
                ))}
            </main>
        </div>
    );
}

const root = createRoot(document.getElementById('root')!);

if (params.has('frame')) {
    const found = scenarios.find((s) => s.id === params.get('scenario')) ?? scenarios[0];
    const theme = (params.get('theme') as Theme) ?? 'system';
    applyTheme(theme);
    boot(found.state(), { mfa: contextFor(found.state()) });
    root.render(<Frame page={found.page} />);
} else {
    root.render(<Shell />);
}
