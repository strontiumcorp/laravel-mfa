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
            'label' => self::label($request->userAgent()),
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

    /** A short name for the browser, e.g. "Chrome on Mac", from its user agent. */
    public static function label(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'A browser',
        };

        $os = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS X') => 'Mac',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $os === null ? $browser : "{$browser} on {$os}";
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
