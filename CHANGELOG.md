# Changelog

## v0.7.0 - 2026-10-10

_Changes since `v0.6.0`._

### Breaking changes

- **ui:** confirm inside the page instead of window.confirm() (da03f5c)

### Fixes

- **ui:** scale the QR code to fit its padded frame (a0e47e9)
- **settings:** reopen the recovery codes step after a full page load (48cc230)

### Documentation

- **plan:** log the settings fixes and in-page confirmations (f91e215)

## v0.6.0 - 2026-10-10

_Changes since `v0.5.2`._

### Breaking changes

- enrollment verification, owner security emails and trusted browsers (19135f7)

### Fixes

- **middleware:** answer blocked non-GET requests with 303, remember only page loads (ef5b26a)

### Documentation

- **plan:** log the artistly audit fixes, trusted browsers and both reviews (10a3f2a)

### Other

- docs (e9b840b)

## v0.5.2 - 2026-10-10

_Changes since `v0.5.1`._

### Fixes

- **ui:** use an example recovery code that can never be a real one (ec74500)
- **ui:** say that each recovery code is a separate, one-line code (1466a48)

## v0.5.1 - 2026-10-10

_Changes since `v0.5.0`._

### Features

- name the recovery codes file after the app, account and date (2ddaba9)

### Fixes

- start the challenge on the authenticator app when the user has one (506677d)
- ask for one recovery code instead of a raw length error (d67c1e8)
- **ui:** keep only the first recovery code when several are pasted (b02b45e)

### Documentation

- **plan:** log the challenge and recovery-code fixes (21bad0c)

## v0.5.0 - 2026-10-09

_Changes since `v0.4.2`._

### Features

- daily caps on login codes per method (50eb3a5)

### Fixes

- don't mistake a superseded code for a used one (cb5e151)
- count the daily code caps per network so a password alone can't lock the owner out (4a2738a)
- **ui:** wait out the cooldown after a used code instead of sending at once (9bcf093)
- keep the resend cooldown across logins (6777ce6)

### Documentation

- known limitations, starting with the password-only verify lockout (651efa4)
- **plan:** bring the plan up to date with the released package and artistly's progress (28de05b)
- **plan:** roadmap with remember-me for MFA (16c00d4)

## v0.4.2 - 2026-10-09

_Changes since `v0.4.1`._

### Fixes

- **ui:** let the settings card's className add to its own classes again (db4cdff)

### Documentation

- install from the public repository (604d01b)

## v0.4.1 - 2026-10-09

_Changes since `v0.4.0`._

### Breaking changes

- **ui:** a settings card that is its own page section (4b15401)

### Fixes

- **ui:** keep the code input and other controls free of host form styles (bc8c250)

### Other

- release straight from main again, keeping the pull-request flow as release-pr (2a7866a)
- check the settings card and API-key notice for dark colours too (bfe0676)
- **preview:** add a Host forms plugin toggle (537e6a9)
- guard the components against host form styles and missing dark colours (efc986f)

## v0.4.0 - 2026-10-09

_Changes since `v0.3.6`._

### Features

- a disabled nudge for impersonated sessions (fa2fc99)
- treat a challenge code as gone once it expires (3fef507)
- tell the challenge page when the code out expires (045f2a9)
- say when sending resumes after an app-wide send cap (28828eb)
- **sms:** cap login-code sends app-wide against SMS pumping (58a2aaa)
- nudge users without two-factor to turn it on (e674956)
- redesign the sign-in challenge around one method at a time (cb6c97c)
- tell the challenge page how many digits each code has (880ef5b)
- send the code when the challenge page opens (38356b8)

### Fixes

- show the countdown, not an error, when a code is already out (c9126dc)
- keep the challenge countdown right after switching methods (cad41c2)
- **sms:** never resend a code that may have been delivered (e1733a6)
- run the MFA gate before route model binding (07cb42e)
- cap a cooldown refusal's retry_after at the code's expiry (39b8f97)
- apply a factor type being turned off or on at once (e271365)
- **audit:** one row per limit window for refused requests (bbc6b68)
- alert on a tripped send breaker once per window, not per fixed hour (fcd37e5)
- **nudge:** record a dismissal only when it hides the nudge (c55794a)
- forget the confirmed password on logout (6dcf697)
- **octane:** resolve policies and the nudge from the live container (4ee78d2)
- keep the resend cooldown on the challenge page after a refresh (553eb80)

### Performance

- read the "has MFA" cache once per request (db8cd2a)

### Refactoring

- one delivered check on the challenge page (256eab5)
- **nudge:** one rule for who the nudge is for (e20a9e1)
- name UiResponse::backQuietly() for what it skips (f779bd3)
- carry the challenge send state as a value object (39e570d)

### Documentation

- **plan:** challenge countdown, cooldown and nudge fixes (131dbd9)
- the challenge countdown across methods and refused resends (dbfd1b1)
- log the 2026-10-09 audit follow-ups in the plan (2ad339c)
- the nudge to turn on two-factor (332ee28)

### Other

- release through a pull request now that main is protected (4db5fe4)

## v0.3.6 - 2026-10-09

_Changes since `v0.3.5`._

### Features

- name the environment in the authenticator app outside production (3faafa1)

## v0.3.5 - 2026-10-09

_Changes since `v0.3.0`._

### Features

- one setup dialog for every sign-in method (627b626)
- redesign the MFA settings page around one card per method (2cfdb9c)

### Fixes

- start each method setup clean, and resume a pending one only once (144c521)
- ask for the password inside the card where the change started (83996b9)
- keep a wrong code in its field, selected, instead of clearing it (23ce881)
- limit authenticator code inputs to 6 digits (4c600c6)

### Documentation

- log the settings redesign and the UI preview in the plan (2f8280d)

### Other

- make preview, a live UI preview of the pages and components (4852088)

## v0.3.0 - 2026-10-09

_Changes since `v0.2.0`._

### Breaking changes

- confirm the password on the MFA settings page itself (bffc756)
- remove Mfa::enforceUsing() in favour of enforcement.policy (45fa886)

### Features

- settings-card component for the account settings page (9bf34ce)

### Documentation

- log the v0.3 work in the integration plan (62087d1)

## v0.2.0 - 2026-10-09

_Changes since `v0.1.1`._

### Breaking changes

- standalone React components, recommended types, required factor types for enforced users (c8139e4)

### Documentation

- split the README into a quickstart, an integration guide and a reference (a0f7c41)

## v0.1.1 - 2026-10-09

_Changes since `v0.1.0`._

### Other

- publish the GitHub Release after a tag's full matrix passes (2f3d3cd)

## v0.1.0 - 2026-10-09

_Initial release._

### Features

- own MFA rows by user_id foreign key instead of a morph (9d696f9)
- shared MFA context for the frontend and an API key notice (384051f)
- more integration checks in mfa:doctor (39a1e36)
- enforce MFA by role list or Mfa::enforceUsing() (D5) (c7e4514)
- require a verified impersonator for targets with MFA (D9) (cd75571)
- queue delivery by queue name, discard codes that never arrived (acb2923)
- add testing helpers for host apps (405811c)
- add React/Inertia challenge and settings pages (0a4832a)
- add install, doctor, status and reset commands (13804b0)
- add middleware, challenge and settings routes (6205b37)
- add events, audit log and metrics (012461b)
- add SMS drivers with failover and routing (6d71242)
- add rate limits and send guard (31b4572)
- add TOTP, email and SMS factors (8904542)
- add config, migrations and models (6373aa5)

### Fixes

- reset mfa:doctor counters on every run (a0bfd1d)
- let Mfa::extend() replace a factor that was already built (2ecd83b)
- round the cooldown retry_after up on Carbon 2 (333d3a5)
- leave the challenge with a full page visit under Inertia (1e4b131)
- key send-limit cache keys with APP_KEY (9168b6d)
- **sms:** short timeouts, no duplicate retries, redacted errors (2ec1eac)
- resolve the SMS sender only for SMS deliveries (b4987b9)
- always let the configured logout route through (7c99c20)

### Documentation

- add CLAUDE.md with goals, workflow and invariants (5f19a7a)
- Mfa::extend() replaces built-in factors; it can't add new types (4d4f42c)
- document make release (daf769b)
- user_id ownership and deletion behaviour; close R5 in the plan (e929be9)
- close decisions D3-D10 and reorder rollout in the plan (6119134)
- enforcement roles, frontend context, API key notice, queued delivery (4fe3539)
- record the second review in the integration plan (822d6bd)
- list all SMS drivers and note the logout exemption (eefc90c)
- add integration plan (f5ffe81)
- add README and JSON mode reference (be23e29)

### Other

- ignore Python caches and common local files (26768ba)
- no breaking-changes section in the first release's notes (b1181c5)
- pin runners to ubuntu-24.04 (71fa965)
- run 7 key combinations per push, the full matrix on tags and nightly (10ed8d8)
- move to the Node 24 releases of checkout and cache (0f695e7)
- cache Composer and PHPStan, parallel Pint, coverage in the matrix (2d01ef1)
- stop fixtures leaking between test files in one worker (42e13ee)
- cover behaviour the coverage report showed as untested (575c04b)
- freeze the clock where tests assert an exact wait (bbca072)
- test lowest dependencies locally too (8e15c97)
- alias a fixture class to fake Socialite on PHP 8.2 (1339405)
- add make release (b669830)
- add test matrix workflow and CODEOWNERS (fd36a1f)
- add unit, feature and architecture suite (1f540a9)
- scaffold package and dev tooling (ee9e1dc)
