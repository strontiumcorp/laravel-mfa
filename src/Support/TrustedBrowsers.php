<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\TrustedBrowserRevocation;
use StrontiumCorp\LaravelMfa\Events\BrowserTrusted;
use StrontiumCorp\LaravelMfa\Events\TrustedBrowsersForgotten;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaTrustedBrowser;

/**
 * "Don't ask again on this browser for N days" (config mfa.trusted_browsers).
 *
 * After a challenge the user may trust the browser: it gets a cookie (per
 * guard and user, so others signing in on it are still asked) holding a
 * random token, and the table keeps only a keyed hash of it. The user still
 * signs in with their password; only the MFA step is skipped while the
 * trust lasts. It ends at expiry, when the password changes (the row keeps
 * a keyed hash of the password hash), when a sign-in method is added or
 * removed, when a recovery code is used, on mfa:reset, and from the
 * settings page. Logging out doesn't end it: that's the point.
 *
 * Cost: nothing for verified sessions or browsers without the cookie; one
 * indexed query when an unverified user with factors presents one.
 */
final class TrustedBrowsers
{
    private const SCOPE = 'trusted-browser';

    /** Session: the trusted browser this session runs on, per guard and user ({id, expires_at}). */
    public const SESSION_KEY = 'mfa.trusted_until';

    public function __construct(
        private readonly Mfa $mfa,
        private readonly CodeHasher $hasher,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Whether this user may trust a browser: trusted_browsers.enabled, and
     * for users enforcement applies to, trusted_browsers.allow_enforced.
     */
    public function offeredTo(MultiFactorAuthenticatable $user): bool
    {
        if (! config('mfa.trusted_browsers.enabled')) {
            return false;
        }

        // Asked once: the lifetime profile depends on it too.
        $enforced = $this->mfa->isEnforced($user);

        return (config('mfa.trusted_browsers.allow_enforced') || ! $enforced)
            // A window or idle timeout (mfa.lifetime) would be undone by the cookie the moment it ends.
            && ! $this->mfa->lifetime()->hasWindow($user, $enforced);
    }

    /** How long a browser stays trusted. */
    public function days(): int
    {
        return max(1, (int) config('mfa.trusted_browsers.days'));
    }

    /** Trust this browser for $user on $guard: a row and the cookie (queued on the response). */
    public function trust(Request $request, MultiFactorAuthenticatable $user, string $guard): MfaTrustedBrowser
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays($this->days());

        // Trusting it again (a renewal) replaces its earlier row.
        if (($previous = $this->sentCookie($request, $guard, $user)) !== null) {
            $this->find($user, $guard, $previous['token'])?->delete();
        }

        /** @var MfaTrustedBrowser $browser */
        $browser = MfaTrustedBrowser::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'guard' => $guard,
            'token_hash' => $this->hasher->hash($token, self::SCOPE),
            'password_hash' => $this->passwordHash($user),
            'label' => self::labelFor($request),
            'user_agent' => ($agent = $request->userAgent()) === null || $agent === '' ? null : mb_substr($agent, 0, self::USER_AGENT_LENGTH),
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        Cookie::queue($this->cookie($guard, $user, $token, $this->days() * 1440));
        $this->remember($request, $guard, $user, $browser);

        $this->events->dispatch(new BrowserTrusted($user, null, null, [
            'trusted_browser_id' => $browser->getKey(),
            'label' => $browser->label,
            'expires_at' => $expiresAt->toIso8601String(),
        ]));

        return $browser;
    }

    /**
     * For an unverified user with factors: if this browser is trusted for
     * them on $guard, mark the session verified and say so. A cookie that no
     * longer works (expired, forgotten, password changed) is removed.
     */
    public function attempt(Request $request, MultiFactorAuthenticatable $user, string $guard): bool
    {
        $cookie = $this->sentCookie($request, $guard, $user);

        if ($cookie === null || ! $this->offeredTo($user)) {
            return false;
        }

        $name = $cookie['name'];
        $browser = $this->find($user, $guard, $cookie['token']);

        // Normally gone already (ForgetTrustedBrowsers::passwordChanged()); this covers a
        // password changed without model events, e.g. a query builder update.
        if ($browser !== null && ! $this->matchesPassword($user, $browser)) {
            $this->forget($user, TrustedBrowserRevocation::PasswordChanged, (int) $browser->getKey());
            $browser = null;
        }

        if ($browser === null) {
            Cookie::queue(Cookie::forget($name, config('session.path'), config('session.domain')));

            return false;
        }

        $browser->forceFill(['last_used_at' => now()])->save();
        $this->mfa->markVerified($request, $user, null, ['via' => 'trusted_browser', 'trusted_browser_id' => $browser->getKey()]);
        $this->remember($request, $guard, $user, $browser);

        return true;
    }

    /**
     * The user's trusted browsers, for the settings page; "current" is this one.
     *
     * @return list<array{id: int, label: string|null, created_at: string|null, last_used_at: string|null, expires_at: string, current: bool}>
     */
    public function list(Request $request, MultiFactorAuthenticatable $user): array
    {
        $current = [];
        foreach (MfaTrustedBrowser::query()->where('user_id', $user->getAuthIdentifier())->distinct()->pluck('guard') as $guard) {
            if (($cookie = $this->sentCookie($request, (string) $guard, $user)) !== null) {
                $current = [...$current, ...$this->hasher->candidates($cookie['token'], self::SCOPE)];
            }
        }

        return MfaTrustedBrowser::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get()
            // Trusted under an older password: no longer trusted (attempt() removes them).
            ->filter(fn (MfaTrustedBrowser $b) => $this->matchesPassword($user, $b))
            ->map(fn (MfaTrustedBrowser $b) => [
                'id' => (int) $b->getKey(),
                'label' => $b->label,
                'created_at' => $b->created_at?->toIso8601String(),
                'last_used_at' => $b->last_used_at?->toIso8601String(),
                'expires_at' => $b->expires_at->toIso8601String(),
                'current' => in_array($b->token_hash, $current, true),
            ])
            ->values()
            ->all();
    }

    /** Stop trusting the user's browsers (or one of them). Returns how many. */
    public function forget(MultiFactorAuthenticatable $user, TrustedBrowserRevocation $cause, ?int $id = null): int
    {
        $query = MfaTrustedBrowser::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->when($id !== null, fn ($q) => $q->whereKey($id));
        $ids = $query->pluck('id')->map(fn ($v) => (int) $v)->all();
        $count = $query->delete();

        // This session's browser was among them: no renewal reminder for it any more.
        $session = $this->liveSession();
        foreach ($session === null ? [] : (array) $session->get(self::SESSION_KEY, []) as $guard => $users) {
            $current = $users[(string) $user->getAuthIdentifier()]['id'] ?? null;
            if ($current !== null && in_array((int) $current, $ids, true)) {
                $session->forget(self::SESSION_KEY.'.'.$guard.'.'.$user->getAuthIdentifier());
            }
        }

        if ($count > 0) {
            $this->events->dispatch(new TrustedBrowsersForgotten($user, null, null, array_filter([
                'count' => $count, 'cause' => $cause->value, 'trusted_browser_id' => $id,
            ], fn ($v) => $v !== null)));
        }

        return $count;
    }

    /**
     * The renewal reminder's window (trusted_browsers.reminder): when this
     * session runs on a trusted browser, [when its trust ends, when the
     * reminder starts], else null. Read from the session first, so most
     * pages end there without asking the enforcement rules.
     *
     * @return array{0: int, 1: int}|null
     */
    public function reminderWindow(Request $request, MultiFactorAuthenticatable $user, string $guard): ?array
    {
        $expires = $request->hasSession() ? $request->session()->get(self::SESSION_KEY.'.'.$guard.'.'.$user->getAuthIdentifier().'.expires_at') : null;
        $hours = max(0, (int) config('mfa.trusted_browsers.reminder.hours'));

        if (! is_numeric($expires) || $hours === 0 || ! $this->offeredTo($user)) {
            return null;
        }

        return [(int) $expires, (int) $expires - $hours * 3600];
    }

    /** Whether this session runs on a browser trusted for this user (renewal is possible). */
    public function runsOnTrustedBrowser(Request $request, MultiFactorAuthenticatable $user, string $guard): bool
    {
        return $request->hasSession() && $request->session()->has(self::SESSION_KEY.'.'.$guard.'.'.$user->getAuthIdentifier());
    }

    /** The longest User-Agent kept with a trusted browser. */
    public const USER_AGENT_LENGTH = 1024;

    /**
     * A short name for the browser of this request, e.g. "Chrome on Mac":
     * from the client hints Chromium-based browsers send (they name Brave,
     * Edge or Opera, and the real platform behind a reduced user agent),
     * else from the user agent. Both come from the browser, so a browser
     * that pretends to be another (DevTools' device mode) is named as it
     * pretends; nothing on the server can tell otherwise.
     */
    public static function labelFor(Request $request): ?string
    {
        return self::label($request->userAgent(), [
            'sec-ch-ua' => $request->headers->get('Sec-CH-UA'),
            'sec-ch-ua-platform' => $request->headers->get('Sec-CH-UA-Platform'),
        ]);
    }

    /**
     * A short name from a user agent and, when given, client hints
     * (`sec-ch-ua`, `sec-ch-ua-platform` header values), which win.
     *
     * @param  array<string, string|null>  $clientHints
     */
    public static function label(?string $userAgent, array $clientHints = []): ?string
    {
        $browser = self::browserFromHints((string) ($clientHints['sec-ch-ua'] ?? ''));
        $os = self::platformFromHints((string) ($clientHints['sec-ch-ua-platform'] ?? ''));

        if (($userAgent === null || $userAgent === '') && $browser === null) {
            return null;
        }

        $agent = (string) $userAgent;
        $browser ??= match (true) {
            str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($agent, 'Edg/') || str_contains($agent, 'EdgA/') || str_contains($agent, 'EdgiOS/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'OPiOS/') => 'Opera',
            str_contains($agent, 'YaBrowser/') => 'Yandex Browser',
            str_contains($agent, 'Vivaldi/') => 'Vivaldi',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $os ??= match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $os === null ? $browser : "{$browser} on {$os}";
    }

    /** The browser named in a `Sec-CH-UA` brand list, skipping Chromium itself and the GREASE brand; null if none. */
    private static function browserFromHints(string $brands): ?string
    {
        if ($brands === '' || strlen($brands) > 512 || preg_match_all('/"([^"]{1,64})";\s*v="[^"]{0,32}"/', $brands, $matches) === 0) {
            return null;
        }

        $names = array_values(array_filter(
            $matches[1],
            // GREASE: a made-up brand ("Not)A;Brand", "Not A(Brand") that only tests parsers.
            fn (string $name) => preg_match('/^Not.?A.?Brand$/i', $name) !== 1,
        ));
        $named = array_values(array_filter($names, fn (string $name) => $name !== 'Chromium'));

        $name = $named[0] ?? ($names === [] ? null : 'Chromium');

        return match ($name) {
            null => null,
            'Google Chrome' => 'Chrome',
            'Microsoft Edge' => 'Edge',
            'Opera', 'Opera GX' => 'Opera',
            'YaBrowser', 'Yandex' => 'Yandex Browser',
            default => preg_match('/^[\w .-]+$/u', $name) === 1 ? $name : null,
        };
    }

    /** The platform from `Sec-CH-UA-Platform`; null when absent or unknown. */
    private static function platformFromHints(string $platform): ?string
    {
        return match (trim($platform, '" ')) {
            'Windows' => 'Windows',
            'macOS' => 'Mac',
            'Linux' => 'Linux',
            'Android' => 'Android',
            'Chrome OS', 'ChromeOS', 'Chromium OS' => 'ChromeOS',
            'iOS' => 'iPhone',
            default => null,
        };
    }

    private function remember(Request $request, string $guard, MultiFactorAuthenticatable $user, MfaTrustedBrowser $browser): void
    {
        $request->session()->put(self::SESSION_KEY.'.'.$guard.'.'.$user->getAuthIdentifier(), [
            'id' => (int) $browser->getKey(),
            'expires_at' => $browser->expires_at->getTimestamp(),
        ]);
        $request->session()->forget(Mfa::REMINDER_DISMISSED);
    }

    private function liveSession(): ?Session
    {
        $container = Container::getInstance();
        $request = $container->bound('request') ? $container->make('request') : null;

        return $request instanceof Request && $request->hasSession() ? $request->session() : null;
    }

    private function find(MultiFactorAuthenticatable $user, string $guard, string $token): ?MfaTrustedBrowser
    {
        /** @var MfaTrustedBrowser|null */
        return MfaTrustedBrowser::query()
            ->whereIn('token_hash', $this->hasher->candidates($token, self::SCOPE))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('guard', $guard)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * One cookie per guard and user, named with a keyed hash so the name
     * doesn't show the user id. This is the name new cookies get (current
     * APP_KEY); sentCookie() also finds ones named under APP_PREVIOUS_KEYS.
     */
    public function cookieName(string $guard, MultiFactorAuthenticatable $user): string
    {
        return $this->cookieNames($guard, $user)[0];
    }

    /** @return non-empty-list<string> the current name first, then the names under previous keys */
    private function cookieNames(string $guard, MultiFactorAuthenticatable $user): array
    {
        $prefix = (string) config('mfa.trusted_browsers.cookie');

        return array_map(
            fn (string $hash) => $prefix.'_'.substr($hash, 0, 16),
            $this->hasher->candidates($guard.'|'.$user->getAuthIdentifier(), self::SCOPE.':cookie'),
        );
    }

    /**
     * The trusted-browser cookie this browser sent for this user and guard.
     *
     * @return array{name: string, token: string}|null
     */
    private function sentCookie(Request $request, string $guard, MultiFactorAuthenticatable $user): ?array
    {
        foreach ($this->cookieNames($guard, $user) as $name) {
            $token = $request->cookie($name);

            if (is_string($token) && $token !== '') {
                return ['name' => $name, 'token' => $token];
            }
        }

        return null;
    }

    /** Whether the browser was trusted under the user's current password. */
    private function matchesPassword(MultiFactorAuthenticatable $user, MfaTrustedBrowser $browser): bool
    {
        return $this->hasher->matches((string) $user->getAuthPassword(), $browser->password_hash, $this->passwordScope($user));
    }

    private function cookie(string $guard, MultiFactorAuthenticatable $user, string $token, int $minutes): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(
            $this->cookieName($guard, $user), $token, $minutes,
            config('session.path'), config('session.domain'), config('session.secure'),
            true, false, config('session.same_site') ?? 'lax',
        );
    }

    private function passwordHash(MultiFactorAuthenticatable $user): string
    {
        return $this->hasher->hash((string) $user->getAuthPassword(), $this->passwordScope($user));
    }

    private function passwordScope(MultiFactorAuthenticatable $user): string
    {
        return self::SCOPE.':password:'.$user->getAuthIdentifier();
    }
}
