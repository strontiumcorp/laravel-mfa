# Integrating into an app

The same steps for every app. Each step says when it applies.

## 1. Install

The repository is public, but the package isn't on Packagist, so add it as a VCS repository in the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/strontiumcorp/laravel-mfa" }
]
```

```bash
composer require strontiumcorp/laravel-mfa:^0.5
```

No credentials are needed, locally, in CI or on servers. Below 1.0 a caret only allows patch releases (`^0.5` takes 0.5.x, not 0.6), so raise the constraint when you upgrade to a new minor version. If Composer hits GitHub's limit for anonymous API requests (busy CI runners), give it a token: `composer config --global github-oauth.github.com <token>`.

## 2. Publish and migrate

```bash
php artisan mfa:install    # config/mfa.php, pages, components
php artisan migrate
```

`mfa:install` publishes into `resources/js/` (`--js-path` for another layout):

```
resources/js/
├── {Pages|pages}/mfa/                 matches the app's pages directory
│   ├── challenge.tsx                  Inertia pages: props in, components out
│   ├── settings.tsx
│   └── mfa-context.ts                 useMfa(), the shared MFA context (Inertia only)
└── components/vendor/laravel-mfa/     always this lowercase path
    ├── challenge-form.tsx             plain React components, see step 6
    └── ...
```

Existing files are skipped; `--force` overwrites them, **`config/mfa.php` included**: every value you changed goes back to the package default (for example `enforcement.roles` returns to `[]`, so admins are no longer forced to enroll). After `--force`, run `git diff config/mfa.php` and restore your values. Settings that come from `.env` are unaffected. To keep `--force` from touching your layouts too, set the pages' layouts outside the published files (step 6). The pages import the components through the `@/` alias for `resources/js/`, which the Laravel starter kits set up in `tsconfig.json` and `vite.config`.

Check that git sees the components: an unanchored `vendor/` line in the app's `.gitignore` (rather than `/vendor`) also ignores `resources/js/components/vendor/`, so they would never be committed. Anchor it, or add `!/resources/js/components/vendor/` after it; `git status` should then list the folder.

After an upgrade, run `php artisan migrate` again: new versions can add tables (v0.6 adds `mfa_trusted_browsers`). If you published the migrations, publish the new ones too (`php artisan vendor:publish --tag=mfa-migrations`; already-published files are skipped).

The migration adds `user_id` foreign keys to the users table, so `users.id` must be a big integer (Laravel's default `$table->id()`).

## 3. User model

```php
use StrontiumCorp\LaravelMfa\Concerns\HasMultiFactorAuthentication;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

class User extends Authenticatable implements MultiFactorAuthenticatable
{
    use HasMultiFactorAuthentication;
}
```

The trait reads the user's email from `email` and their role from `role` (a string or an enum). Override `getMfaEmail()` or `getMfaRoles()` if the columns differ.

## 4. Configure

`.env`:

```dotenv
MFA_TOTP_ENABLED=true
MFA_EMAIL_ENABLED=true
MFA_SMS_ENABLED=false            # SMS stays off for now; Twilio is ready when needed
MFA_SMS_DRIVER=twilio
MFA_DELIVERY_QUEUE=mfa           # send codes from a queued job; needs a worker on this queue
# MFA_LOG_CHANNEL=mfa            # optional; must name a channel in config/logging.php. Unset = the app's default channel
```

Add the same variables to `.env.example`.

**A worker must serve `MFA_DELIVERY_QUEUE`.** The queue name is new, so nothing processes it until you add it everywhere the app defines workers, or email and SMS codes are queued and never sent:

- Horizon: add it to each supervisor's `queue` list in `config/horizon.php`, for every environment that runs Horizon (often only `local`). Put it first so a backlog on other queues never delays sign-in codes.
- pm2, Supervisor or systemd: a process running `php artisan queue:work --queue=mfa` (on the connection the job uses: `MFA_DELIVERY_QUEUE_CONNECTION`, else the default). A pm2 app added to `ecosystem.config.cjs` doesn't start on `pm2 restart <name>`; run `pm2 startOrReload ecosystem.config.cjs` on each server once.

The worker needs no `--tries`: the job sets its own tries and backoff (`delivery.tries`, `delivery.backoff`). With `QUEUE_CONNECTION=sync` codes are sent inline, so a local setup without workers still works.

`config/mfa.php`:

| Key | Set to |
|---|---|
| `enforcement.roles` | The roles that must use MFA, e.g. `['admin', 'super_admin', 'support']`. For logic in code, set `enforcement.policy` to a `Contracts\EnforcementPolicy` class instead (with `roles` empty, it decides alone). |
| `enforcement.required_types` | What those users must set up and sign in with. Default `['totp']`: an admin with only email is sent to add an authenticator app. `[]` accepts any factor. |
| `routes.home` | Where users land after the challenge when there's no intended page. Default `/dashboard`. |
| `routes.logout_route` | The app's named logout route. Default `logout`. |
| `routes.password_confirmation` | Keep `true`: the MFA settings page asks for the password before factor changes, with no confirm page needed in the app. Users with an empty password are never asked. If social-login users have a password they don't know, exempt them with `routes.password_confirmation_policy` (a `Contracts\PasswordConfirmationPolicy` class), or set `false` to ask nobody. `mfa:doctor` warns when Socialite is installed. See [configuration.md](configuration.md#password-confirmation). |
| `routes.confirm_middleware` | `[]` (default). `['password.confirm']` sends users to the app's own confirm-password page instead of MFA's prompt. |
| `enrollment_verification` | Keep the default: an enforced user proves they own the account with a code emailed to them before adding their first method, so a leaked password alone can't enroll the attacker's authenticator. For users without an email address, or roles whose mailbox can't be trusted (`email => false`), support issues a one-time link with `php artisan mfa:enrollment-link <email>`. See [configuration.md](configuration.md#enrollment-verification). |
| `trusted_browsers` | Off by default. `MFA_TRUSTED_BROWSERS=true` offers "Don't ask again on this browser for 30 days" on the challenge; `allow_enforced` extends it to admins (weigh it: a stolen trusted browser plus the password skips their second factor). See [configuration.md](configuration.md#trusted-browsers). |
| `notifications` | On by default: owners are emailed when a method is added or removed, recovery codes are created or used, and when codes keep being requested. Needs a working mailer; turn off any you already send. See [configuration.md](configuration.md#security-notifications). |
| `middleware.except` | Routes a logged-in user must reach before passing MFA (e.g. a language switcher). Requests without a session login, such as webhooks, already pass. |

## 5. Check the app's auth code

Search the app for these patterns. Each needs a change only when it's present.

**API-key auth calling `login()`.** Change `auth()->login($user)` to `auth()->setUser($user)`. `login()` writes a session cookie, which turns the key into a browser session. MFA isn't enforced for API keys, so show the notice next to API key settings (step 6).

**Login-swap impersonation** (`Auth::loginUsingId($target)` on a browser request). Grant MFA for the target right after the swap:

```php
$admin = auth()->user();
Auth::loginUsingId($request->user_id);

/** @var \App\Models\User $target loginUsingId() just succeeded */
$target = auth()->user();   // nullable to static analysis, hence the @var
Mfa::grantForImpersonation($admin, $target);
```

The target hasn't passed MFA in this session, so without the grant the admin is sent to the target's challenge (target with MFA) or to enroll the target (enforced target without MFA). Impersonating a user without MFA works either way, which makes the gap easy to miss in testing.

It throws if the target has MFA and the admin hasn't passed MFA in this session. When every role that can impersonate is enforced, the MFA middleware stops an unverified admin before the impersonation route, so the throw can't happen from a browser and a `catch` is optional. It also drops the admin's password confirmation, so the admin can't change the target's factors. Per-request impersonation through `Auth::setUser()` needs nothing. Ending a login-swap impersonation with `Auth::login($admin)` needs nothing either: logging in again keeps the admin's verification in the session (only a logout clears it).

**`login()` or `loginUsingId()` outside browser requests** (jobs, commands). Fine when queued, because nothing is persisted. Dispatched synchronously from a web request, it would change that request's logged-in user.

**Sanctum stateful API** (`statefulApi()` or `EnsureFrontendRequestsAreStateful`). Those `api` routes use the session but sit outside the `web` group. Add the `mfa` middleware to them. Token-based `auth:sanctum` is unaffected.

**Trusted proxies.** The per-IP send limits need the real client IP. Behind a load balancer, list the proxy IPs. Trust `'*'` only if the app is reachable solely through exactly one proxy.

## 6. Frontend

The published files are the app's own: restyle and rearrange them freely. `mfa:install` never overwrites them without `--force`.

- Put the published pages in the app's layouts. Set the layouts from the page resolver rather than editing the pages, so `mfa:install --force` doesn't undo them. The page names are `ui.pages` in the config (`mfa/challenge`, `mfa/settings`):

  ```jsx
  // resources/js/app.jsx, in createInertiaApp({ resolve })
  resolve: (name) => {
      const page = resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx'));
      page.then((mod) => {
          if (name === 'mfa/settings') {
              mod.default.layout ??= (page) => <AuthenticatedLayout settings={page.props.settings}>{page}</AuthenticatedLayout>;
          }
      });
      return page;
  },
  ```

  A persistent layout gets the page element, so layout props come from `page.props`. The challenge page draws its own card and needs no layout, but see the next point.
- The components use Tailwind's `dark:` variants with the `class` strategy, so the `dark` class must be on `<html>` (or another ancestor) whenever the app is dark. If the app keeps dark mode in state or a cookie and only some layouts set the class, a page rendered outside those layouts (often the challenge page) shows light components on a dark page or the reverse. Give it a layout that only syncs the class, e.g. one that calls the app's dark-mode hook and returns `<>{children}</>`.
- Add the two-factor card to the account settings page. It says whether two-factor is on and links to the MFA settings page, and hides itself when MFA or its routes are off:

  ```tsx
  import MfaSettingsCard from '@/components/vendor/laravel-mfa/settings-card';
  import { mfaSettingsCardProps, useMfa } from '@/pages/mfa/mfa-context'; // or @/Pages/...
  import { Link } from '@inertiajs/react';

  <MfaSettingsCard {...mfaSettingsCardProps(useMfa())} renderLink={(link) => <Link {...link} />} />
  ```

  It's a complete card (light and dark), so render it as a section of the page, not inside another card. `className` adds to its own classes (e.g. `mx-auto max-w-3xl`); to match the page's other sections, override what differs with Tailwind's important modifier, e.g. `className="!border-0 !shadow dark:!bg-[#1E1F24]"`. Without `renderLink` it renders a plain `<a>`. It needs the shared context below; without it, the card stays hidden.
- Share the MFA context in `HandleInertiaRequests::share()`:

  ```php
  'mfa' => fn () => \StrontiumCorp\LaravelMfa\Facades\Mfa::context($request),
  ```

  React code reads it with `useMfa()` from `@/{Pages|pages}/mfa/mfa-context`.
- Show the API-key notice next to API key management:

  ```tsx
  import MfaApiKeyNotice from '@/components/vendor/laravel-mfa/api-key-notice';
  import { mfaApiKeyNoticeProps, useMfa } from '@/pages/mfa/mfa-context'; // or @/Pages/...

  <MfaApiKeyNotice {...mfaApiKeyNoticeProps(useMfa())} />
  ```
- Mount the "turn on two-factor" nudge once, in the app's global (authenticated) layout. It floats in a corner for a user who has no method and isn't enforced, never on the MFA pages, and "Not today" hides it until the user's next local midnight on every device (see [Nudge](configuration.md#nudge)):

  ```tsx
  import MfaEnableNudge from '@/components/vendor/laravel-mfa/enable-nudge';
  import { useMfaNudge } from '@/pages/mfa/mfa-context'; // or @/Pages/...

  <MfaEnableNudge {...useMfaNudge()} />
  ```

  `useMfaNudge()` reads the shared context and adds the dismiss request (`router.post` with the browser's timezone, keeping scroll and state) and Inertia's `<Link>`. Pass `position` (`'bottom-right'` by default, or `top-left`, `top-center`, `top-right`, `bottom-left`, `bottom-center`), `offset` or `className` next to it, e.g. `<MfaEnableNudge {...useMfaNudge()} position="top-center" offset={[24, 80]} />` to clear a fixed header. Without Inertia, take `mfaNudgeProps(mfa)` and pass your own `onDismiss` (POST `{ timezone }` to its `dismissUrl`) and `renderLink`. It needs the shared context; without it, it stays hidden.

  The same card also carries the [trusted browser](configuration.md#trusted-browsers) reminder: in the last 12 hours of a browser's trust it says "Two-factor check coming up … This browser will ask for your sign-in code again in 5 hours", with **Verify now** (the challenge, early, then back to the page) and **Later** (hidden for the session). `useMfaNudge()` picks whichever applies (they never apply to the same user: the nudge is for users without a method) and posts each one's dismissal to its own URL; `kind` says which (`'enable'` or `'trust-reminder'`), and `closeLabel` is the × button's name (`enable-nudge` gains that optional prop, default "Dismiss for today"). The context key is `trustReminder`.

  While an admin impersonates a user, pass your app's own "is impersonating" state as `disabled`, e.g. `<MfaEnableNudge {...useMfaNudge()} disabled={isImpersonating} />`. The admin can still close it for the page they're on, but nothing is saved, so they can't hide the user's nudge on the user's devices.

#### Background requests

The gate answers a script's `fetch()` or axios call with a JSON `403` (`error: "mfa_required"` or `"mfa_enrollment_required"`, plus `redirect`), not with the challenge page, and doesn't let it change where the user lands after verifying ([Blocked requests](configuration.md#blocked-requests)). Inertia visits need nothing: they follow the redirect. For the app's own scripts (polling, autocomplete, bulk actions), send the browser to the challenge on that error, once, in the app's entry file:

```js
// resources/js/app.jsx (or bootstrap.js)
const MFA_ERRORS = ['mfa_required', 'mfa_enrollment_required'];

// axios
window.axios.interceptors.response.use(null, (error) => {
    const data = error.response?.data;
    if (error.response?.status === 403 && MFA_ERRORS.includes(data?.error)) {
        window.location.assign(data.redirect);
    }
    return Promise.reject(error);
});

// fetch
const nativeFetch = window.fetch.bind(window);
window.fetch = async (...args) => {
    const response = await nativeFetch(...args);
    if (response.status === 403 && response.headers.get('content-type')?.includes('application/json')) {
        const data = await response.clone().json().catch(() => null);
        if (MFA_ERRORS.includes(data?.error)) window.location.assign(data.redirect);
    }
    return response;
};
```

Both only redirect on the MFA errors; every other `403` reaches the caller as before. A page left open in another tab then lands on the challenge (or the settings page) as soon as it polls, and after verifying, the user returns to the last page they actually opened.

**The components** in `components/vendor/laravel-mfa/` are plain React: no Inertia, no Ziggy, and none imports another. Their only shared file is `icons.tsx`, which holds every icon they draw (`MfaIconAuthenticator`, `MfaIconEmail`, `MfaIconSms`, `MfaIconKey`, `MfaIconShieldAlert`, `MfaIconShieldCheck`, `MfaIconShieldLock`, `MfaIconCheck`, `MfaIconChevronLeft`, `MfaIconChevronRight`, and `MfaFactorIcon` by type; each takes `size`, `className`, `title`). To use another icon set (lucide, Heroicons, your own), edit only `icons.tsx` and keep the exported names. A component copied into another React project needs `icons.tsx` next to it. They take data and callbacks as props, plus `processing`, `error` and, for sends, `retryAfter`:

| Component | Props |
|---|---|
| `challenge-form` | The challenge, one method at a time, in its own card: the method's icon and title ("Open your authenticator app", "Check your email", "Check your phone"), a code input with one box per digit (one real `<input>` with `autocomplete="one-time-code"`, so autofill and paste fill every box), Verify, and a footer with "Try another way" (a list of the methods and, with `onUseRecoveryCode`, "Recovery code") and "Sign out". `factors` (each one's `code_length` sets the boxes, default 6; an email/SMS factor's `code_sent` switches the copy from "We're sending a code to …" to "Enter the 6-digit code we sent to …"), `selectedFactorId`, `onSelectFactor(id)`, `onSubmit(code)`, `processing`, `error`, `children` (shown under the code input, e.g. `send-code-button`), `onUseRecoveryCode?`, `onSignOut?`, `sent?` (overrides `code_sent`, e.g. right after a send), `sendFailed?` ("We couldn't send a code to …"), `expired?` ("The code we sent to … has expired"; the challenge page passes it when a factor's optional `expires_in` runs out), `waiting?` ("You recently used a code sent to …"; the challenge page passes it when no code is out and the next one has to wait), `initialView?` (`'code'` or `'methods'`, to open on the list), `trustBrowserDays?` (shows "Don't ask again on this browser for N days" above Verify; `null` hides it; [trusted browsers](configuration.md#trusted-browsers)), `trustBrowserDefault?` (the box starts ticked), `onCancel?` and `cancelLabel?` (default "Not now": a way out without verifying, shown before Sign out). With the box offered, `onSubmit` is called as `onSubmit(code, { trustBrowser })`; otherwise as `onSubmit(code)`. |
| `send-code-button` | The "Didn't get it? Resend in 0:58" line, then "Didn't get it? Send a new code" (just "Send code" before any code is out, e.g. after a failed send, and "You can get a new code in 0:58" while none is out and a wait runs). Waits of an hour or more show as `h:mm:ss`. `onSend()`, `processing`, `retryAfter`, `sent`, `error` |
| `recovery-code-form` | The recovery code step in the same card style. `onSubmit(code)`, `processing`, `error`, `onTryAnotherWay?` (back to the list), `onUseVerificationCode?` (shown instead when `onTryAnotherWay` isn't given), `onSignOut?`. Pasting several codes (lines from the saved list) keeps the first. |
| `factor-cards` | The settings page's method list: one card per method. `types` (`{ type, label, recommended? }`), `factors` (confirmed; `confirmed_at` shows as "Added"), `onAdd(type, destination?)`, `onStart(type)` (when given, Set up hands the setup to the page for every type, e.g. to `factor-setup-dialog`, instead of asking for an email/SMS destination inline), `onRemove(factor)`, `setups` (per type, the setup in progress to show inside that card, e.g. `{ sms: <MfaDestinationSetup framed={false} … /> }`), `passwordPrompt` (`{ at: { factor: id } | { type }, node }`: the password prompt, shown inside the card where the change started), `adding`, `error`, `removingId`, `required`, `requiredTypes`, `confirmRemove?`, `notice?` (`{ title, body }`: a note above the methods, e.g. the settings page's `nudge`; hidden while `required`) |
| `factor-list` | A plain list (the v0.2 layout; `factor-cards` replaces it on the settings page). `factors`, `onRemove(factor)`, `removingId`, `required`, `requiredLabels`, `confirmRemove?` |
| `add-factor-form` | Buttons to add a method (the v0.2 layout; `factor-cards` covers it). `types` (`{ type, label, recommended? }`; recommended ones get a badge and go first), `onAdd(type, destination?)`, `processing`, `error` |
| `factor-setup-dialog` | How the settings page adds every method: one dialog with steps that follow the props. Password first (`askPassword`; again if the server asks mid-way), then "Confirm it's you" before an account's first method (`verifyEmail`: an emailed code, or the administrator-link message when its `email` is `null`; again if the server asks mid-way), then the QR code and key (authenticator app; "Open in authenticator app" on phones) or the address/number (email, SMS), then the code, then the recovery codes (Complete unlocks once they're copied or downloaded; that step can't be dismissed) or a short "added" step. A bottom sheet on phones, centred from `sm` up. `open`, `type`, `label`, `askPassword`, `onConfirmPassword(password)`, `passwordProcessing`, `passwordError`, `passwordRetryAfter`, `verifyEmail` (`{ email } | null`, the settings prop `enrollmentVerification`), `onSendEmailCode()`, `emailCodeSending`, `emailCodeSent` (shows the code input), `emailCodeRetryAfter`, `onVerifyEmailCode(code)`, `emailCodeProcessing`, `emailCodeError`, `onSubmitDestination(destination)`, `destinationProcessing`, `destinationError`, `secret`, `qrSvg`, `otpauthUrl`, `sentTo`, `onResend()`, `resending`, `retryAfter`, `onConfirm(code)`, `processing`, `error`, `confirmed`, `recoveryCodes`, `onClose()`, `onComplete()`, `recoveryCodesFile` (`{ app, slug, account }`, the settings prop of that name: the download is named `{slug}-recovery-codes-{account}-{YYYY-MM-DD}.txt` with the browser's local date, characters file names can't hold replaced by `-`, and its text names the app, the account and when it was downloaded), `downloadName` (a fixed file name instead) |
| `totp-setup` | The same setup inline, without steps (for pages that don't want a dialog). `secret`, `qrSvg`, `otpauthUrl` (the settings page's `otpauth_url`; on phones an "Open in authenticator app" button, since a phone can't scan its own screen), `onConfirm(code)`, `processing`, `error`, `label`, `framed` (default `true`; `false` drops its box and heading, inside a card). The key shows in groups of four with a Copy button. |
| `destination-setup` | `label`, `destination`, `onConfirm(code)`, `onResend()`, `processing`, `resending`, `retryAfter`, `sent`, `error`, `framed` |
| `recovery-codes-panel` | `remaining`, `total` (shows "8 of 10 left" and a meter; the settings page's `recoveryCodesTotal`), `codes`, `onRegenerate()`, `processing`, `confirmRegenerate?`, `passwordPrompt` (shown inside the card, for New codes) |
| `api-key-notice` | `enabled`, `settingsUrl`, `className` |
| `settings-card` | The "Two-factor authentication" section for an account page: a heading with an On / Off / Required badge, a line of text, and Manage (or Set up) underneath. It draws its own card in light and dark, so it needs no wrapper. `settingsUrl` (renders nothing when `null`), `enabled`, `hasMfa`, `mustEnroll`, `className` (added to the card's own classes; override one with Tailwind's important modifier, e.g. `dark:!bg-[#1E1F24]`), `renderLink?({ href, className, children })` |
| `enable-nudge` | The floating "turn on two-factor" card: an icon, `title` and `body`, × and `dismissLabel` (both call `onDismiss` and hide it at once), and `button`, a link to `settingsUrl` (none when `null`). `show` (renders nothing when false), `title`, `body`, `button`, `dismissLabel`, `settingsUrl`, `onDismiss(timezone)` (the browser's timezone, or `undefined` if it can't tell), `position?` (default `'bottom-right'`), `offset?` (default `24`: a number is px, a string any CSS length, a pair is `[x, y]`; distance from the chosen edges, `x` ignored for `*-center`), `className?`, `renderLink?({ href, className, children })`, `disabled?` (still shows, but × and `dismissLabel` only hide it for this page view, without calling `onDismiss`; "Turn on" still works). On phones (below `sm`) it spans the width with a 16px gutter; from `sm` up it's 320px wide. A non-modal `region` labelled by its title; it never takes the focus. |
| `trusted-browsers-panel` | The settings page's list of [trusted browsers](configuration.md#trusted-browsers): each one's label ("Chrome on Mac", or "Unknown browser"), a "This browser" badge, when it was last used (or added) and until when it is trusted, with Forget; Forget all when there are several. `browsers` (the settings prop `trustedBrowsers`: `{ id, label, created_at, last_used_at, expires_at, current }[]`; `[]` shows a short note), `onForget(id)`, `onForgetAll?()`, `forgettingId?`, `forgettingAll?`, `className?` |
| `password-confirm-form` | `onConfirm(password)`, `onCancel?`, `processing`, `error`, `retryAfter` (the settings page's `passwordRetryAfter`), `framed` (default `true`; `false` inside a card), `className`. On phones: the field, then Cancel and Confirm side by side, Confirm on the right. |

The settings page adds every method through `factor-setup-dialog`. It asks for the password first when the settings prop `passwordConfirmationRequired` says a change would need it, and again if the server asks mid-way (the confirmation expired), then retries the same address or number. A setup left pending (e.g. after a reload) reopens the dialog; closing it early leaves "Continue setup" in the card. The recovery codes from a first method appear in the dialog, not again in `recovery-codes-panel`. For Remove and New codes, a "password confirmation required" answer shows `password-confirm-form` inside that card (the method's card, or the recovery card), so the user's focus stays put, and the change is retried once the password is confirmed. After a failed attempt (`processing` goes back to false with an `error`), a code input keeps the code and selects it, so the user sees what they typed and can fix a digit or just type over it; the password input clears. `retryAfter` drives a countdown; a new value restarts it. `totp-setup` renders `qrSvg` as HTML, so pass only the server's `qr_svg`.

**Host styles.** The components set their own control styles: every `<input>` and `<button>` has an explicit `type`, and each text field states its border, radius, padding, background and text colours (light and dark), placeholder colour and focus ring. So they look the same in an app that uses `@tailwindcss/forms` (whose base styles restyle text inputs and add a blue focus ring) or a global `input {}` rule in its Tailwind base layer. The challenge's code boxes are drawn by spans over one invisible `<input>`, which resets border, padding, shadow and ring. They still rely on the app's Tailwind (v3 or v4) compiling the classes, its preflight (`box-sizing: border-box`, no default margins), the `dark` class strategy for dark mode, and the app's font. CSS outside any layer (e.g. a theme stylesheet with plain `input { … }` rules, loaded after Tailwind v4) wins over Tailwind utilities, so scope such rules to the app's own forms. `make preview` has a "Host forms plugin" toggle (`?forms=1`) to check this. Components published at v0.4.0 or earlier don't have this: re-publish `challenge-form`, `recovery-code-form`, `factor-setup-dialog`, `factor-cards`, `password-confirm-form`, `totp-setup`, `destination-setup` and `add-factor-form` (`php artisan mfa:install --force`, which overwrites customised files, or copy them from `stubs/inertia-react/`).

**Adding the nudge (after v0.3.6).** New: the `enable-nudge` component, `MfaIconShieldLock` in `icons.tsx`, `mfaNudgeProps()` and `useMfaNudge()` in `mfa-context.ts` (the context gains `nudge`), and a `notice` prop on `factor-cards` that the settings page fills from its new `nudge` prop. Run `php artisan mfa:install` for the new component; take `icons.tsx`, `factor-cards.tsx`, `mfa-context.ts` and `settings.tsx` with `--force` or copy them from `stubs/inertia-react/`. Then mount it in the layout as above. Set `MFA_NUDGE_ENABLED=false` to keep it off.

**Upgrading for trusted browsers (after v0.5.2).** The challenge props gain `trustBrowser` and `renew`, the settings props `trustedBrowsers` and `urls.forgetTrustedBrowser(s)`, and the shared context `trustReminder`. Changed: `challenge-form` (the checkbox and "Not now"), `enable-nudge` (`closeLabel`), `icons.tsx` (`MfaIconBrowser`), the challenge and settings pages and `mfa-context.ts`; new: `trusted-browsers-panel`. Re-publish them together (`php artisan mfa:install --force`, or copy them from `stubs/inertia-react/`), and run `php artisan migrate`. Older pages keep working while `MFA_TRUSTED_BROWSERS` is off (the default); turn it on only after republishing.

**Upgrading to enrollment verification (after v0.5.2).** The settings props gain `enrollmentVerification` and `urls.sendEnrollmentCode` / `urls.verifyEnrollmentCode`, and `factor-setup-dialog` a "Confirm it's you" step (`verifyEmail` and the `emailCode*` props). Re-publish the settings page and `factor-setup-dialog` together (`php artisan mfa:install --force`, or copy them from `stubs/inertia-react/`). Until then, an enforced user adding their first method on an old page gets the server's "Enter the code we email you…" error with no way to enter it, so republish before (or with) the upgrade, or set `MFA_ENROLLMENT_VERIFICATION=null` until you do.

**Upgrading the recovery codes download (after v0.5.0).** The settings props gain `recoveryCodesFile`, and `factor-setup-dialog` its `recoveryCodesFile` prop: the downloaded file is named after the app, the account and the date, and its text says so (it was `recovery-codes.txt`). Re-publish the settings page and `factor-setup-dialog` together (`php artisan mfa:install --force`, or copy them from `stubs/inertia-react/`). Without the prop the dialog names the file `recovery-codes-{YYYY-MM-DD}.txt`; components published earlier keep `recovery-codes.txt`.

**Upgrading the settings card (after v0.4.0).** `settings-card` now draws its own card (heading, badge, text, and the action underneath), so remove the wrapper you put around it. `className` adds to the card's own classes, so spacing such as `className="mt-4"` keeps working; to change part of the card's look, use Tailwind's important modifier (`!shadow`, `dark:!bg-[#1E1F24]`). (v0.4.1 replaced the classes instead; that was reverted because layout-only classes silently removed the card.) `api-key-notice` gained dark-mode colours.

**Upgrading the challenge page (after v0.4.2).** The resend cooldown now spans logins: after a code was used to verify, the challenge page can get `code_sent: false` with a `retry_after`. The page then waits instead of sending at once (that send would be refused), says "You recently used a code sent to …", and sends when the wait ends. `challenge-form` gains the optional `waiting` prop and `send-code-button` the "You can get a new code in …" wording. Re-publish the challenge page with both components (`php artisan mfa:install --force`, or copy them from `stubs/inertia-react/`). An older page still works: its send is refused with the cooldown, it shows that error and the countdown, and the user taps "Send code" when it ends.

**Upgrading the challenge page (after v0.3.6).** The challenge page shows one method at a time, with "Try another way" for the others, and the components draw their own card. `challenge-form`, `send-code-button`, `recovery-code-form` and `icons.tsx` (two chevrons) changed, and challenge factors gain `code_length`. The components' props are unchanged apart from new optional ones, but the old method buttons and "Use a recovery code" link are gone (both live in the "Try another way" list), and `children` now sits under the code input. Re-publish the challenge page and those components together (`php artisan mfa:install --force`, or copy them from `stubs/inertia-react/`).

**Upgrading from v0.3.** The settings page is redesigned: new components `factor-cards` and `factor-setup-dialog`, and `icons.tsx`, which every component now imports for its icons (swap the icon set there). `recovery-codes-panel`, `password-confirm-form`, `totp-setup` and `destination-setup` were restyled and gained props the new page passes (`total`, `passwordPrompt`, `framed`, `otpauthUrl`), and the settings props gain `recoveryCodesTotal` and `passwordConfirmationRequired`. Take the page and the components together: re-publish with `php artisan mfa:install --force` (this overwrites customised files), or copy the changes from `stubs/inertia-react/` in the package. `factor-list` and `add-factor-form` are still published for pages that use them.

**Upgrading from v0.2.** Two new components (`settings-card`, `password-confirm-form`), a `mfaSettingsCardProps()` helper in `mfa-context.ts`, and a settings page that asks for the password inline. Run `php artisan mfa:install` to add the new components (existing files are skipped); to get the new settings page and helper, re-publish with `--force` (this overwrites customised pages) or copy the changes from `stubs/inertia-react/pages/` in the package. Then check `routes.password_confirmation` in step 4: the [config upgrade note](configuration.md#password-confirmation) says what changed.

**Upgrading from v0.1.** v0.1 published `api-key-notice.tsx` and `mfa-context.ts` to `{Components|components}/mfa/`, and self-contained pages. Run `php artisan mfa:install --force` (this overwrites customised pages), change the imports as above (`<MfaApiKeyNotice />` now takes its state as props), then delete `{Components|components}/mfa/`. `mfa:install` warns while those old files remain.

## 7. Tests

Tests that use `actingAs()` keep passing: it never writes the session, so MFA doesn't apply. Tests that sign a user in for real (posting to the login route, app code calling `Auth::login()`, impersonation, a password change that logs the user back in) do get MFA. With `enforcement` on, those users are sent to enroll, and `grantForImpersonation()` refuses an enforced impersonator who was only `actingAs()`'d. Start such tests from `actingAsMfaVerified()`, or mark the session verified right after the real login:

```php
// e.g. a helper in tests/Pest.php
function markMfaVerified(User $user): void
{
    session()->put(\StrontiumCorp\LaravelMfa\Facades\Mfa::sessionKey('web', $user->id), now()->getTimestamp());
}

$this->post(route('login'), ['email' => $admin->email, 'password' => 'secret']);
markMfaVerified($admin);
```

For MFA tests, use the helpers:

```php
uses(\StrontiumCorp\LaravelMfa\Testing\InteractsWithMfa::class);

$this->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
$this->actingAsMfaVerified($user)->get('/dashboard')->assertOk();
```

Add tests for: an admin forced to enroll, impersonating a user who has MFA and switching back, the settings page asking for the password before a change (`withConfirmedPassword()` skips it), a webhook and an API key still working for a user with MFA.

An enforced user's first method also needs proof of ownership ([enrollment verification](configuration.md#enrollment-verification)): `$this->loginWithSession($admin)->withEnrollmentVerified($admin)` skips it, or fake notifications and post the emailed code to `mfa.enrollment-verification.verify`. MFA's own security emails are sent through Laravel notifications, so `Notification::fake()` in a test keeps them out of the mailer.

## 8. Verify and deploy

```bash
php artisan mfa:doctor     # exits non-zero on problems
```

Manual check:
- Password login with MFA → challenge → the intended page.
- Wrong code → error; the email code arrives on its own when the challenge opens on email, and a refresh keeps the countdown without sending another; recovery code works once.
- "Sign out" on the challenge page works.
- An admin without MFA is sent to enroll, gets an email code before adding a method, and gets a "method added" email afterwards.
- A tab left open on a page that polls the backend lands on the challenge (or enrollment) after the session ends, and verifying returns to the page, not to the polled URL.
- Impersonation, webhooks and API keys still work.
- `php artisan mfa:status <email>` shows the attempts.

Deploy:
- Run `mfa:doctor` in the pipeline.
- Run a queue worker for `MFA_DELIVERY_QUEUE` in every environment's process manager (step 4).
- Keep the scheduler running (it prunes old codes and audit rows daily).
- Use a shared cache and session store (Redis or database) on multiple servers.

To switch MFA off in an incident, set `MFA_ENABLED=false`.

## Laravel versions

| Version | Notes |
|---|---|
| 11 | End of life, and every 11.x release has open security advisories. Composer 2.9 blocks insecure versions by default, but only among the packages it installs or updates: `composer require strontiumcorp/laravel-mfa` leaves the framework alone, and in an app locked to Laravel 11.45 it installed with an advisory warning. A command that updates the framework can still fail; resolve that in the app (upgrade, or an explicit audit decision). Kernel-style apps (`app/Http/Kernel.php`) work as is; `mfa:doctor` confirms the middleware is in the `web` group and reads `App\Http\Middleware\TrustProxies`. |
| 12 | Nothing specific. |
| 13 | Nothing specific. |

Under Octane, run the test suite and one manual login with `octane:start` to confirm nothing leaks between requests.
