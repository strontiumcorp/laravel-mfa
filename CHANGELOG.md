# Changelog

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
