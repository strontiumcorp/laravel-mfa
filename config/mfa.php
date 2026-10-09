<?php

use StrontiumCorp\LaravelMfa\Events\ChallengeRequired;
use StrontiumCorp\LaravelMfa\Notifications\EnrollmentCodeNotification;
use StrontiumCorp\LaravelMfa\Notifications\OtpCodeNotification;
use StrontiumCorp\LaravelMfa\Notifications\SecurityAlertNotification;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When disabled, the middleware lets every request through and the MFA
    | routes are not registered. Useful as a kill-switch during incidents.
    |
    */

    'enabled' => env('MFA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    |
    | Session guards whose logins must pass MFA. The middleware checks the
    | identity stored in the session by these guards (not Auth::user()), so
    | per-request user swaps such as Auth::setUser() never trigger a challenge.
    |
    */

    'guards' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | The model MFA rows belong to (user_id foreign keys). null = the model of
    | the first guard's user provider. Every guard above must use this model.
    | Deleting a user deletes their factors, codes and recovery codes; their
    | audit rows are kept (user_id set to null) until the retention prune.
    |
    */

    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Enforcement
    |--------------------------------------------------------------------------
    |
    | Who must use MFA. With no roles and no policy, MFA is opt-in per user.
    | A user is enforced when either rule says so.
    |
    | roles          => roles that must use MFA, e.g. ['admin', 'support'],
    |                   matched against the user's getMfaRoles() (the "role"
    |                   attribute by default; string or enum).
    | policy         => a class implementing Contracts\EnforcementPolicy, for
    |                   logic in code (resolved from the container, so it
    |                   can inject anything). With no roles, it decides alone.
    | required_types => what enforced users must set up and sign in with.
    |                   Their other factors don't count: an enforced user
    |                   with only email is sent to add an authenticator app,
    |                   and once they have one, the challenge offers only it
    |                   (recovery codes still work). Types that are disabled
    |                   are ignored; [] means any enabled type.
    |
    */

    'enforcement' => [
        'roles' => [],
        'policy' => null,
        'required_types' => ['totp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enrollment verification
    |--------------------------------------------------------------------------
    |
    | Before an account's first factor is added, the session must prove it
    | owns the account beyond its password. Otherwise someone with only a
    | leaked password could add their own authenticator app to an account
    | that must enroll, and the account would then be theirs.
    |
    | required_for => "enforced": users an enforcement rule applies to (they
    |                 can reach nothing but the MFA settings page anyway).
    |                 "everyone": every user adding a first factor (also
    |                 stops a password-only attacker from locking an opt-in
    |                 owner out). null or false: never.
    | email        => accept a code sent to the account's email address
    |                 (getMfaEmail()), proving access to the inbox. false =
    |                 only an administrator's one-time link proves it
    |                 (mfa:enrollment-link / Mfa::enrollmentLink()), for apps
    |                 where the mailbox can't be trusted. Users with no email
    |                 address always need a link.
    | link_ttl     => minutes an administrator's link stays valid (one use,
    |                 the user's own signed-in session, revoked by a password
    |                 change).
    | notification => the email with the code. Same constructor arguments as
    |                 the default (code, TTL in seconds).
    |
    | The code reuses factors.email (length, ttl, max_attempts,
    | resend_cooldown) and counts toward the account's send caps.
    |
    */

    'enrollment_verification' => [
        'required_for' => env('MFA_ENROLLMENT_VERIFICATION', 'enforced'),
        'email' => true,
        'link_ttl' => 1440,
        'notification' => EnrollmentCodeNotification::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted browsers
    |--------------------------------------------------------------------------
    |
    | "Don't ask again on this browser for N days", a checkbox on the
    | challenge. The user still signs in with their password (and is logged
    | back in by remember-me as before); only the MFA step is skipped on that
    | browser, for that user, until it expires. Ends early when the password
    | changes, a sign-in method is added or removed, a recovery code is used,
    | on mfa:reset, or from the settings page. Logging out doesn't end it.
    | Never offered after signing in with a recovery code.
    |
    | enabled        => offer it at all (off by default).
    | days           => how long a browser stays trusted.
    | allow_enforced => offer it to users enforcement applies to (admins).
    |                   Weigh it: a stolen trusted browser plus the password
    |                   then skips their second factor.
    | cookie         => cookie name prefix (one cookie per guard and user).
    | reminder       => in the last `hours` of a browser's trust, the nudge
    |                   card (MfaEnableNudge) says the challenge is coming,
    |                   so it doesn't arrive unannounced mid-task. "Verify
    |                   now" passes the challenge early (trusting the browser
    |                   for another `days`) and returns to the page. 0 = never.
    |                   :when becomes e.g. "in 5 hours". Through __().
    |
    */

    'trusted_browsers' => [
        'enabled' => env('MFA_TRUSTED_BROWSERS', false),
        'days' => 30,
        'allow_enforced' => false,
        'cookie' => 'mfa_trusted',
        'reminder' => [
            'hours' => 12,
            'title' => 'Two-factor check coming up',
            'body' => "This browser will ask for your sign-in code again :when. Do it now so it doesn't interrupt you later.",
            'button' => 'Verify now',
            'dismiss_label' => 'Later',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security notifications
    |--------------------------------------------------------------------------
    |
    | Emails the account owner (getMfaEmail()) when their two-factor setup
    | changes or looks attacked, so they notice someone else acting with
    | their password. Never contains codes or secrets: what happened, when,
    | and from which IP address. Sent like codes: queued on the delivery
    | queue (delivery.*) when one is set, inline otherwise. A failing send
    | is reported and never blocks the request.
    |
    | events => one switch per event:
    |   factor_enabled           a sign-in method was added
    |   factor_disabled          a sign-in method was removed (also mfa:reset)
    |   recovery_codes_generated new recovery codes (not the first set,
    |                            which comes with factor_enabled)
    |   recovery_code_used       someone signed in with a recovery code
    |   suspicious_code_requests sign-in codes keep being requested without
    |                            being used, or hit the send cap (someone
    |                            may have the password); at most one an hour
    |   enrollment_link_issued   an administrator's setup link was created
    |                            (mfa:enrollment-link / Mfa::enrollmentLink())
    | notification => the email. Receives the event's name (as above) and
    |                 its details (method, IP address, time); see
    |                 Notifications\SecurityAlertNotification.
    |
    */

    'notifications' => [
        'enabled' => env('MFA_NOTIFICATIONS_ENABLED', true),
        'events' => [
            'factor_enabled' => true,
            'factor_disabled' => true,
            'recovery_codes_generated' => true,
            'recovery_code_used' => true,
            'suspicious_code_requests' => true,
            'enrollment_link_issued' => true,
        ],
        'notification' => SecurityAlertNotification::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Factors
    |--------------------------------------------------------------------------
    */

    'factors' => [

        'totp' => [
            'enabled' => env('MFA_TOTP_ENABLED', true),
            // Listed first and badged "Recommended" when users add a method.
            'recommended' => true,
            // The name authenticator apps show for this account.
            'issuer' => env('MFA_TOTP_ISSUER', env('APP_NAME', 'Laravel')),
            // Outside production, add the environment in brackets ("Acme (staging)"),
            // so test accounts don't look like the real one in the app. Applies
            // to apps added from now on; existing entries keep their name.
            'issuer_environment' => true,
            // Number of 30s steps accepted either side of "now" (clock drift).
            'window' => 1,
        ],

        'email' => [
            'enabled' => env('MFA_EMAIL_ENABLED', true),
            'recommended' => false,
            'length' => 6,
            'ttl' => 600,               // seconds a code stays valid
            'max_attempts' => 5,        // wrong guesses before a code is burned
            // Wait between sends: base × multiplier^(n-1), capped at max
            // (2 → 4 → 8 → 15 min), where n counts the codes sent in the last
            // hour. A successful verification doesn't reset it, so logging in
            // again and again can't send a code each time: after a used code
            // the next one waits one step lower, from that code's send (the
            // first re-login is free). A resend is allowed at once when the
            // current code has expired or been burned. Use an int for a flat
            // cooldown.
            'resend_cooldown' => ['base' => 120, 'multiplier' => 2, 'max' => 900],
            // Email codes per account per network (client IP; IPv6 per /64)
            // per 24 hours (login and enrollment, every address), counted
            // from the first. Bounds someone who has the password and the
            // inbox and keeps logging in again; per network, so someone with
            // only the password, elsewhere, can't use up the owner's codes.
            // Users behind one IP share it, and sends with no IP share one
            // bucket. When it is reached the user is told when to try again,
            // and an authenticator app or recovery code still works.
            // null or 0 = no cap.
            'send_per_day' => 15,
            'notification' => OtpCodeNotification::class,
        ],

        'sms' => [
            'enabled' => env('MFA_SMS_ENABLED', false),
            'recommended' => false,
            'length' => 6,
            'ttl' => 600,               // carriers can be slow; matches email
            'max_attempts' => 5,
            'resend_cooldown' => ['base' => 120, 'multiplier' => 2, 'max' => 900],
            // SMS codes per account per network per 24 hours, counted apart
            // from email and lower, since every text costs money. As for
            // email (per network, IPv6 per /64): null or 0 = no cap.
            'send_per_day' => 5,
            // E.164 calling codes allowed to receive SMS (toll-fraud guard).
            // Empty array = allow all (not recommended in production).
            'allowed_calling_codes' => ['1', '44'],
            // Digit prefixes (country code included) that never receive SMS,
            // checked at enrollment AND at every send. "+1" covers more than
            // the US/Canada: these are Caribbean NANP ranges and US 900
            // premium numbers that are common SMS-pumping destinations.
            // Pair this with your provider's geo-permissions / fraud guard.
            'blocked_prefixes' => [
                '1242', '1246', '1264', '1268', '1284', '1345', '1441', '1473',
                '1649', '1658', '1664', '1721', '1758', '1767', '1784', '1809',
                '1829', '1849', '1868', '1869', '1876', '1900',
            ],
            'message' => ':code is your :app verification code. It expires in :minutes minutes.',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | SMS transport
    |--------------------------------------------------------------------------
    |
    | Drivers talk to provider REST APIs through Laravel's HTTP client, so no
    | vendor SDK is required. "log" writes messages to the log (local/dev).
    | Register your own with Mfa::extendSms('name', fn ($app, $config) => ...).
    |
    | Built in: log, twilio, vonage, infobip, sns, plus two composites:
    |   failover — tries "drivers" in order until one accepts the message
    |   routing  — picks a driver by number prefix ("routes"), else "default"
    |
    | A driver's name selects its transport unless it sets "transport", so you
    | can define several of the same kind (e.g. a second failover chain).
    |
    */

    'sms' => [
        'driver' => env('MFA_SMS_DRIVER', 'log'),

        'drivers' => [
            'log' => [
                'channel' => env('MFA_SMS_LOG_CHANNEL'),
            ],
            'twilio' => [
                'sid' => env('TWILIO_SID'),
                'token' => env('TWILIO_TOKEN'),
                'from' => env('TWILIO_FROM'),
                // Use a Messaging Service SID instead of "from" if you have one.
                'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
            ],
            'vonage' => [
                'key' => env('VONAGE_KEY'),
                'secret' => env('VONAGE_SECRET'),
                'from' => env('VONAGE_SMS_FROM'),
            ],
            'infobip' => [
                'api_key' => env('INFOBIP_API_KEY'),
                // Your personal base URL from the Infobip portal (lowest latency).
                'base_url' => env('INFOBIP_BASE_URL', 'https://api.infobip.com'),
                'from' => env('INFOBIP_FROM'),
            ],
            // Amazon SNS — works from any host (self-hosted included): only an
            // IAM key with sns:Publish and a region are needed, no AWS SDK.
            'sns' => [
                'key' => env('AWS_SNS_KEY'),
                'secret' => env('AWS_SNS_SECRET'),
                'token' => env('AWS_SNS_SESSION_TOKEN'),          // temporary credentials only
                'region' => env('AWS_SNS_REGION', 'us-east-1'),
                'sms_type' => 'Transactional',                       // OTPs: reliability over price
                'sender_id' => env('AWS_SNS_SENDER_ID'),             // countries that support alphanumeric IDs
                'origination_number' => env('AWS_SNS_ORIGINATION_NUMBER'), // e.g. a 10DLC / toll-free number
            ],

            // Composite examples (set MFA_SMS_DRIVER=failover or routing to use):
            'failover' => [
                'drivers' => ['twilio', 'vonage'],
            ],
            'routing' => [
                'routes' => [
                    // '880' => 'infobip',   // Bangladesh via Infobip
                ],
                'default' => 'failover',
            ],
            // A second, named chain:
            // 'bd' => ['transport' => 'failover', 'drivers' => ['infobip', 'twilio']],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Code delivery
    |--------------------------------------------------------------------------
    |
    | Both null => send email/SMS synchronously (immediate error feedback).
    | Either set => dispatch delivery as a job: queue_connection alone uses
    |         that connection, queue alone uses that queue on the default
    |         connection. The payload is encrypted, retried with backoff, and
    |         a final failure emits an event and discards the unsent code so
    |         the user can request another one right away. A "sync"
    |         connection falls back to inline sending.
    |
    */

    'delivery' => [
        'queue_connection' => env('MFA_DELIVERY_QUEUE_CONNECTION'),
        'queue' => env('MFA_DELIVERY_QUEUE'),
        'tries' => 3,
        'backoff' => [5, 15, 30],
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery codes
    |--------------------------------------------------------------------------
    */

    'recovery_codes' => [
        'count' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Backed by the default cache store. In multi-server deployments this must
    | be a shared store (redis, database, memcached), not "file" or "array".
    |
    */

    'rate_limit' => [
        'verify_per_minute' => 5,
        // Long-window cap: stops slow brute force of 6-digit codes that stays
        // under the per-minute limit. Cleared on successful verification.
        'verify_per_day' => 50,
        // Every code sent, per account (login and enrollment).
        'send_per_hour' => 10,
        // Password attempts at the MFA settings page's password prompt, per
        // account; the daily cap stops slow guessing from a stolen session.
        // Both are cleared when the password is confirmed.
        'password_per_minute' => 5,
        'password_per_day' => 20,

        // Unconfirmed destinations — a number/email being added, which anyone
        // can trigger toward anyone. Kept separate from login sends, so these
        // limits can't be used to lock an owner out of their login codes.
        'unconfirmed_per_destination_per_day' => 2,   // across ALL accounts
        'new_destinations_per_account_per_day' => 3,   // distinct destinations
        'new_destinations_per_ip_per_hour' => 10,     // distinct destinations; IPv6 per /64
        'unconfirmed_global_per_hour' => 500,         // app-wide circuit breaker

        // App-wide cap on login codes (confirmed destinations), against SMS
        // pumping through many accounts that each stay under send_per_hour.
        // When hit, codes pause for everyone until the hour's window frees
        // up (SendingCircuitTripped, critical). Set it well above your peak:
        // 1000 an hour is about 17 a minute, sustained; null or 0 = no cap.
        'confirmed_global_per_hour' => 1000,

        // Fire SuspiciousCodeRequests after this many login-code sends with
        // no successful verification (0 = never). Someone may have the
        // password: the owner is emailed (notifications.events).
        'warn_after_unverified_sends' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | "Does this user have MFA?" is answered on every request for logged-in
    | users who have not verified yet. The answer is cached and invalidated
    | whenever a factor changes. null store = default cache store.
    |
    */

    'cache' => [
        'store' => env('MFA_CACHE_STORE'),
        'ttl' => 3600,
        'prefix' => 'mfa',
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | append_to_web_group: secure by default — every route in the "web" group
    | is protected. Set to false to apply the "mfa" alias manually instead.
    |
    | except: route-name or path patterns that are never challenged. MFA's own
    | routes and logout are always allowed.
    |
    */

    'middleware' => [
        'append_to_web_group' => env('MFA_APPEND_TO_WEB_GROUP', true),
        'except' => [
            'logout',
            'webhook/*',
            'api/webhook/*',
        ],

        // Reachable by users who must enroll but have no factor yet, so the
        // app's own password confirmation page (routes.confirm_middleware)
        // doesn't loop back to the settings page. Route names or paths.
        // MFA's own password prompt needs no entry here.
        'allow_while_enrolling' => [
            'password.confirm',
            'confirm-password',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'mfa',
        'middleware' => ['web', 'auth'],
        // Ask for the password again before adding or removing a factor or
        // regenerating recovery codes, at most once per auth.password_timeout
        // (Laravel's setting, read as is). The MFA settings page asks itself,
        // so the app needs no confirm-password page. It shares Laravel's
        // session key (auth.password_confirmed_at) with password.confirm.
        //
        // Never asked: users whose stored password is empty, and users the
        // policy below exempts. Set to false to never ask anyone: an
        // MFA-verified session is then enough.
        'password_confirmation' => true,
        // A class implementing Contracts\PasswordConfirmationPolicy that
        // decides per user, e.g. exempting social-login accounts that never
        // chose a password. null = ask everyone who has a password.
        'password_confirmation_policy' => null,
        // Extra middleware on those same routes, run before the check above,
        // e.g. ['password.confirm'] to use the app's own confirm-password
        // page instead (it sets the same session key, so MFA won't ask again).
        'confirm_middleware' => [],
        // Where to send the user after a successful challenge when there is
        // no "intended" URL.
        'home' => '/dashboard',
        // Request throttle on the MFA routes themselves ("max,minutes"), on
        // top of the per-user verify/send limits. Bounds audit/log volume.
        'throttle' => '60,1',
        // Named route the challenge page's "Sign out" button posts to. Apps
        // whose logout lives elsewhere (e.g. POST /admin/logout) still work
        // as long as the route is named. null hides the button.
        'logout_route' => 'logout',
    ],

    /*
    |--------------------------------------------------------------------------
    | Nudge
    |--------------------------------------------------------------------------
    |
    | A dismissible card (the MfaEnableNudge component, mounted in the app's
    | layout) asking users who have no factor to turn two-factor on. Shown
    | only while MFA and its routes are on, to logged-in users with no factor
    | who aren't enforced (enforced users are sent to enroll anyway), and
    | never on MFA's own pages. The MFA settings page shows the same title
    | and body as a notice to those users.
    |
    | "Not today" hides it until the user's next local midnight (the browser
    | sends its timezone; app.timezone if it doesn't), at most 26 hours,
    | for that user on every device. Plain strings (config:cache safe); each
    | goes through __(), so a lang/{locale}.json entry translates it.
    |
    */

    'nudge' => [
        'enabled' => env('MFA_NUDGE_ENABLED', true),
        'title' => 'Protect your account',
        'body' => 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
        'button' => 'Turn on',
        'dismiss_label' => 'Not today',
    ],

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    |
    | driver: "inertia" renders the pages below; "json" returns JSON so any
    | frontend can drive the flow.
    |
    */

    'ui' => [
        'driver' => env('MFA_UI_DRIVER', 'inertia'),
        'pages' => [
            'challenge' => 'mfa/challenge',
            'settings' => 'mfa/settings',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    |
    | Every MFA action emits a domain event (StrontiumCorp\LaravelMfa\Events\*).
    | The package listens to its own events and fans them out to:
    |
    |  - log:     one structured line per event on the given channel
    |  - audit:   a queryable row in mfa_audit_logs (see `php artisan mfa:status`)
    |  - metrics: a counter via Contracts\MetricsRecorder (bind your own for
    |             Prometheus / StatsD / Pulse; "log" and "null" ship built in)
    |
    | Every event in one challenge carries the same flow_id, also pushed into
    | Laravel's Context so your own log lines in that request carry it too.
    |
    */

    'observability' => [
        'log' => [
            'enabled' => env('MFA_LOG_ENABLED', true),
            'channel' => env('MFA_LOG_CHANNEL'),
            'level' => env('MFA_LOG_LEVEL', 'info'),
        ],
        'audit' => [
            'enabled' => env('MFA_AUDIT_ENABLED', true),
            'retention_days' => 90,
            // High-volume, low-value events kept out of the audit table.
            'ignore' => [
                ChallengeRequired::class,
            ],
        ],
        'metrics' => [
            'driver' => env('MFA_METRICS_DRIVER', 'null'), // null | log | FQCN
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Schedules `model:prune` daily for expired OTP codes and audit rows older
    | than the retention period. Requires the scheduler to be running.
    |
    */

    'prune' => [
        'schedule' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    */

    'tables' => [
        'factors' => 'mfa_factors',
        'otp_codes' => 'mfa_otp_codes',
        'recovery_codes' => 'mfa_recovery_codes',
        'audit_logs' => 'mfa_audit_logs',
        'trusted_browsers' => 'mfa_trusted_browsers',
    ],

];
