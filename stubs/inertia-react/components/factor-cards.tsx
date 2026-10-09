// Published by strontiumcorp/laravel-mfa. This file is yours — restyle freely.
//
// The sign-in methods as one card each: active methods with their details and
// a Remove button, the others with "Set up" (email and SMS ask for the
// destination inside their card). A setup in progress renders inside its
// method's card. Needs only React and ./icons (the icon set, swappable in one
// place); imports no other component and knows nothing about Inertia or routes.
//
//     <MfaFactorCards
//         types={availableTypes}
//         factors={factors}
//         setups={{ totp: <MfaTotpSetup framed={false} … /> }}
//         onAdd={(type, destination) => store(type, destination)}
//         onRemove={(factor) => destroy(factor.id)}
//         required={mustEnroll}
//         requiredTypes={requiredTypes.map((t) => t.type)}
//     />
import { useEffect, useState, type ReactNode } from 'react';
import { MfaFactorIcon, MfaIconCheck, MfaIconShieldAlert, MfaIconShieldLock, type MfaFactorType } from './icons';

export type { MfaFactorType };

export type MfaCardFactor = {
    id: number;
    type: MfaFactorType;
    type_label: string;
    label: string | null;
    /** Masked, e.g. "j***@example.com". */
    destination: string | null;
    confirmed_at?: string | null;
    last_used_at: string | null;
};

export type MfaFactorCardsProps<F extends MfaCardFactor = MfaCardFactor> = {
    /** The types the user may add, with their display labels. Recommended ones go first. */
    types: { type: MfaFactorType; label: string; recommended?: boolean }[];
    /** The user's confirmed methods. */
    factors: F[];
    /**
     * Called with the type, and for email/SMS the destination as typed. An
     * empty email destination means "use the account email".
     */
    onAdd: (type: MfaFactorType, destination?: string) => void;
    /**
     * When given, "Set up" calls this for every type instead of asking for an
     * email/SMS destination inline: the page runs the setup itself, e.g. in
     * MfaFactorSetupDialog. onAdd is then not called.
     */
    onStart?: (type: MfaFactorType) => void;
    /** Called after the user confirms the removal. */
    onRemove: (factor: F) => void;
    /** A setup in progress per type (QR code, code entry), shown inside that type's card. */
    setups?: Partial<Record<MfaFactorType, ReactNode>>;
    /**
     * A password prompt (e.g. <MfaPasswordConfirmForm framed={false} … />), shown
     * inside the card where the change started: a method's card (`factor`, for
     * Remove) or a type's card (`type`, for Set up). The user's focus stays put.
     */
    passwordPrompt?: { at: { factor: number } | { type: MfaFactorType }; node: ReactNode } | null;
    /** Adding is in flight. */
    adding?: boolean;
    /** From adding, e.g. an invalid phone number. */
    error?: string | null;
    /** The factor being removed; its button is disabled. */
    removingId?: number | null;
    /** The account must enroll (an enforcement rule applies). */
    required?: boolean;
    /** The types that satisfy the requirement; empty means any. */
    requiredTypes?: MfaFactorType[];
    /** Asks before removing; defaults to window.confirm(). */
    confirmRemove?: (factor: F) => boolean;
    /**
     * A note above the methods for a user who has none yet and isn't
     * required to (the settings page's `nudge`); not shown while `required`.
     */
    notice?: { title: string; body: string } | null;
};

// Phones get the short copy, so cards stay compact; from `sm` up, the full one.
const ABOUT: Record<MfaFactorType, { short: string; long: string }> = {
    totp: { short: 'Codes from an authenticator app.', long: 'Codes from Google Authenticator, 1Password, Authy or similar. Works offline.' },
    email: { short: 'A code sent to your inbox.', long: 'A code sent to your inbox.' },
    sms: { short: 'A code by text message.', long: 'A code by text message. Handy as a backup; an app is safer.' },
};

function Responsive({ short, long }: { short: string; long: string }) {
    if (short === long) return <>{long}</>;

    return (
        <>
            <span className="sm:hidden">{short}</span>
            <span className="hidden sm:inline">{long}</span>
        </>
    );
}

const TILE: Record<MfaFactorType, string> = {
    totp: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300',
    email: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    sms: 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
};

const BADGE = {
    active: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300',
    // Same amber as the page's "Required" status and the enforcement banner.
    required: 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200',
    recommended: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300',
    setup: 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300',
    off: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
};

function Badge({ tone, className = '', children }: { tone: keyof typeof BADGE; className?: string; children: ReactNode }) {
    return <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${BADGE[tone]} ${className}`}>{children}</span>;
}

const formatDate = (iso: string) => new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

// On phones the action gets its own full-width row under the text.
const ACTION = 'col-span-2 w-full sm:col-span-1 sm:w-auto';
const PRIMARY = `${ACTION} min-h-11 shrink-0 rounded-lg bg-gray-900 px-5 text-sm font-semibold text-white disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900`;
const SECONDARY =
    `${ACTION} min-h-11 shrink-0 rounded-lg border border-gray-300 bg-white px-5 text-sm font-medium text-gray-900 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100`;

// Text fields set every part of their look (type, border, padding, colours, focus ring), so a host's
// form styles (@tailwindcss/forms, a global `input {}` rule) can't change them.
const FIELD =
    'appearance-none rounded-lg border border-gray-300 bg-white text-gray-900 shadow-none placeholder:text-gray-400 focus:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900/10 focus:ring-offset-0 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-600 dark:focus:border-gray-100 dark:focus:ring-gray-100/20';

export default function MfaFactorCards<F extends MfaCardFactor>({
    types,
    factors,
    onAdd,
    onStart,
    onRemove,
    setups = {},
    passwordPrompt = null,
    adding = false,
    error = null,
    removingId = null,
    required = false,
    requiredTypes = [],
    confirmRemove = () => window.confirm('Remove this method?'),
    notice = null,
}: MfaFactorCardsProps<F>) {
    // Which email/SMS card is asking for its destination.
    const [entering, setEntering] = useState<Exclude<MfaFactorType, 'totp'> | null>(null);
    const [destination, setDestination] = useState('');

    const close = () => {
        setEntering(null);
        setDestination('');
    };

    // Close the destination form once its code went out: the setup for that
    // type has arrived. (A failure or a password prompt keeps it, with what was typed.)
    const sentSetup = entering ? setups[entering] : null;
    useEffect(() => {
        if (sentSetup) close();
    }, [sentSetup]);

    const promptIn = (at: { factor: number } | { type: MfaFactorType }) => {
        if (!passwordPrompt) return null;
        const p = passwordPrompt.at;
        const here = 'factor' in at ? 'factor' in p && p.factor === at.factor : 'type' in p && p.type === at.type;

        return here ? <div className="border-t border-gray-100 px-4 py-4 sm:px-6 sm:py-5 sm:pl-[6.25rem] dark:border-gray-800">{passwordPrompt.node}</div> : null;
    };

    const start = (type: MfaFactorType) => {
        if (onStart) return onStart(type);
        if (type === 'totp') return onAdd('totp');
        setEntering(type);
        setDestination('');
    };

    const submit = (e: { preventDefault(): void }) => {
        e.preventDefault();
        if (entering) onAdd(entering, destination.trim());
    };

    // Stable: recommended first, otherwise in the given order.
    const ordered = [...types].sort((a, b) => Number(!!b.recommended) - Number(!!a.recommended));
    const labelOf = (type: MfaFactorType) => types.find((t) => t.type === type)?.label;
    const requiredLabels = requiredTypes.map(labelOf).filter((l): l is string => !!l);
    const isRequired = (type: MfaFactorType) => required && requiredTypes.includes(type);
    // The one action to point at: what the account requires, else the recommended method for a first setup.
    const suggested = (type: MfaFactorType, recommended?: boolean) =>
        required ? (requiredTypes.length === 0 ? !!recommended : requiredTypes.includes(type)) : factors.length === 0 && !!recommended;

    // Plain functions, called directly (not components), so typing in a card
    // never remounts it.
    const factorCard = (f: F) => {
        const name = f.label ?? f.type_label;

        return (
            <article key={`f${f.id}`} className="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-3 p-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:gap-x-5 sm:gap-y-4 sm:p-5 sm:px-6">
                    <div className={`flex size-11 shrink-0 items-center justify-center rounded-lg sm:size-14 sm:rounded-xl ${TILE[f.type]}`}>
                        <MfaFactorIcon type={f.type} size={28} className="size-6 sm:size-7" />
                    </div>
                    <div className="min-w-0 grow space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">{name}</h3>
                            <Badge tone="active">Active</Badge>
                        </div>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {f.destination ? (
                                <>
                                    Codes sent to <span className="font-medium text-gray-900 dark:text-gray-100">{f.destination}</span>
                                </>
                            ) : (
                                'Codes from your authenticator app.'
                            )}
                        </p>
                    </div>
                    <button
                        type="button"
                        aria-label={`Remove ${name}`}
                        disabled={removingId === f.id}
                        onClick={() => confirmRemove(f) && onRemove(f)}
                        className={`${ACTION} min-h-11 shrink-0 rounded-lg border border-gray-200 px-4 text-sm font-medium text-red-700 disabled:opacity-50 dark:border-gray-700 dark:text-red-400`}
                    >
                        Remove
                    </button>
                </div>
                <dl className="grid grid-cols-2 gap-3 border-t border-gray-100 px-4 py-3 sm:px-5 sm:py-3.5 text-sm sm:pl-[6.25rem] dark:border-gray-800">
                    <div>
                        <dt className="text-gray-500 dark:text-gray-400">Added</dt>
                        <dd className="text-gray-900 dark:text-gray-100">{f.confirmed_at ? formatDate(f.confirmed_at) : '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-gray-500 dark:text-gray-400">Last used</dt>
                        <dd className="text-gray-900 dark:text-gray-100">{f.last_used_at ? formatDate(f.last_used_at) : 'Never'}</dd>
                    </div>
                </dl>
                {promptIn({ factor: f.id })}
            </article>
        );
    };

    const availableCard = (type: MfaFactorType, label: string, recommended: boolean) => {
        const setup = setups[type];
        const open = !!setup || entering === type;
        // While the password is asked here, it's the card's only next step.
        const prompting = !!passwordPrompt && 'type' in passwordPrompt.at && passwordPrompt.at.type === type;
        const delivered = type !== 'totp';
        const highlight = open || suggested(type, recommended);

        return (
            <article
                key={`t${type}`}
                className={`rounded-2xl bg-white dark:bg-gray-900 ${
                    highlight ? 'border-[1.5px] border-gray-900 ring-4 ring-gray-900/5 dark:border-gray-100 dark:ring-gray-100/10' : 'border border-dashed border-gray-300 dark:border-gray-700'
                }`}
            >
                <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-3 p-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:gap-x-5 sm:gap-y-4 sm:p-5 sm:px-6">
                    <div className={`flex size-11 shrink-0 items-center justify-center rounded-lg sm:size-14 sm:rounded-xl ${TILE[type]}`}>
                        <MfaFactorIcon type={type} size={28} className="size-6 sm:size-7" />
                    </div>
                    <div className="min-w-0 grow space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100">{label}</h3>
                            {/* On phones the dashed card and its "Set up" button already say it. */}
                            {setup ? <Badge tone="setup">Setting up</Badge> : !required && <Badge tone="off" className="hidden sm:inline">Not set up</Badge>}
                            {isRequired(type) && <Badge tone="required">Required</Badge>}
                            {recommended && !setup && (
                                // A required method needs no "Recommended" on phones, where both don't fit.
                                <Badge tone="recommended" className={isRequired(type) ? 'hidden sm:inline' : ''}>
                                    Recommended
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {setup && type === 'totp' ? 'Add this account to your authenticator app, then enter the code it shows.' : <Responsive {...ABOUT[type]} />}
                            {required && requiredTypes.length > 0 && !requiredTypes.includes(type) && (
                                <>
                                    {' '}
                                    <Responsive short="Doesn't count on its own." long="Doesn't meet your account's requirement on its own." />
                                </>
                            )}
                        </p>
                    </div>
                    {!open && (
                        <button
                            type="button"
                            aria-label={`Set up ${label}`}
                            disabled={adding}
                            onClick={() => start(type)}
                            className={suggested(type, recommended) ? PRIMARY : SECONDARY}
                        >
                            Set up
                        </button>
                    )}
                    {entering === type && !setup && !prompting && (
                        <button type="button" onClick={close} className={`${ACTION} min-h-11 shrink-0 px-3 text-sm font-medium text-gray-600 dark:text-gray-400`}>
                            Cancel
                        </button>
                    )}
                </div>

                {open && (
                    <div className="space-y-4 border-t border-gray-100 px-4 pb-5 pt-4 sm:px-5 sm:pb-6 sm:pt-5 sm:pl-[6.25rem] dark:border-gray-800">
                        {delivered && (
                            <ol aria-label="Steps" className="flex flex-wrap gap-5 text-sm">
                                <li className={`flex items-center gap-2 ${setup ? 'text-green-700 dark:text-green-400' : 'font-medium text-gray-900 dark:text-gray-100'}`}>
                                    <span
                                        className={`flex size-6 items-center justify-center rounded-full text-xs ${
                                            setup ? 'bg-green-100 dark:bg-green-900/50' : 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                                        }`}
                                    >
                                        {setup ? (
                                            <MfaIconCheck size={12} title="Done" />
                                        ) : (
                                            '1'
                                        )}
                                    </span>
                                    {type === 'sms' ? 'Phone number' : 'Email address'}
                                </li>
                                <li className={`flex items-center gap-2 ${setup ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'}`}>
                                    <span
                                        className={`flex size-6 items-center justify-center rounded-full text-xs ${
                                            setup ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-gray-100 dark:bg-gray-800'
                                        }`}
                                    >
                                        2
                                    </span>
                                    Enter the code
                                </li>
                            </ol>
                        )}

                        {setup ?? (
                            <form onSubmit={submit} className="space-y-2">
                                <label htmlFor={`mfa-destination-${type}`} className="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {type === 'sms' ? 'Phone number, with country code' : 'Email address (leave empty to use your account email)'}
                                </label>
                                <div className="flex flex-wrap gap-2">
                                    <input
                                        id={`mfa-destination-${type}`}
                                        value={destination}
                                        onChange={(e) => setDestination(e.target.value)}
                                        type={type === 'sms' ? 'tel' : 'email'}
                                        placeholder={type === 'sms' ? '+1 555 555 0100' : 'you@example.com'}
                                        autoFocus
                                        className={`${FIELD} min-h-11 w-full max-w-xs px-3 py-0 text-sm`}
                                    />
                                    <button type="submit" disabled={adding || prompting || (type === 'sms' && destination.trim() === '')} className={PRIMARY}>
                                        Send code
                                    </button>
                                </div>
                                {error && (
                                    <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                        {error}
                                    </p>
                                )}
                            </form>
                        )}
                    </div>
                )}
                {promptIn({ type })}
            </article>
        );
    };

    const cards: ReactNode[] = [];

    for (const t of ordered) {
        for (const f of factors.filter((f) => f.type === t.type)) cards.push(factorCard(f));
        // A type the user already has shows no "Set up" card, unless a setup of it is in progress.
        if (!factors.some((f) => f.type === t.type) || setups[t.type]) cards.push(availableCard(t.type, t.label, !!t.recommended));
    }
    // Methods of a type that is no longer offered still show, so they can be removed.
    for (const f of factors.filter((f) => !types.some((t) => t.type === f.type))) cards.push(factorCard(f));

    return (
        <section className="space-y-3">
            {required && (
                <div role="note" className="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-900 dark:bg-amber-950/40">
                    <MfaIconShieldAlert size={20} className="mt-0.5 shrink-0 text-amber-700 dark:text-amber-300" />
                    <div className="space-y-0.5">
                        <p className="text-sm font-semibold text-amber-900 dark:text-amber-100">
                            {requiredLabels.length === 0 ? 'Your account needs two-factor authentication' : `Your account needs: ${requiredLabels.join(' or ')}`}
                        </p>
                        <p className="text-sm text-amber-800 dark:text-amber-200">Set it up to keep using the app. It takes about a minute.</p>
                    </div>
                </div>
            )}

            {!required && notice && (
                <div role="note" className="flex gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 px-5 py-4 dark:border-indigo-900 dark:bg-indigo-950/40">
                    <MfaIconShieldLock size={20} className="mt-0.5 shrink-0 text-indigo-700 dark:text-indigo-300" />
                    <div className="space-y-0.5">
                        <p className="text-sm font-semibold text-indigo-950 dark:text-indigo-100">{notice.title}</p>
                        <p className="text-sm text-indigo-900 dark:text-indigo-200">{notice.body}</p>
                    </div>
                </div>
            )}

            <h2 className="pt-1 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sign-in methods</h2>

            {cards}

            {error && !entering && (
                <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </section>
    );
}
