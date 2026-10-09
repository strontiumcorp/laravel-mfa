<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Session\Session;

/**
 * "Not today" on the turn-on-two-factor nudge (config mfa.nudge): hidden for
 * that user until their next local midnight, on every device.
 *
 * The cache holds it per user (an HMAC key, expiring at that instant); the
 * session mirrors it, so once a session knows, a page view reads nothing.
 * Times are whole Unix timestamps, never diffs (Carbon 2 truncates those).
 * Holds no state, so it is safe under Octane.
 */
final class Nudge
{
    /** Session key prefix, followed by the user id. Cleared on logout. */
    public const SESSION_PREFIX = 'mfa.nudge';

    /** The longest a dismissal lasts, whatever the timezone says. */
    public const MAX_HOURS = 26;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {}

    /**
     * Whether this user dismissed the nudge and it hasn't come back yet. The
     * session answers first; only without an answer there is the cache read
     * (at most once), and a hit is remembered in the session.
     */
    public function isDismissed(Session $session, Authenticatable $user): bool
    {
        $key = $this->sessionKey($user);
        $now = CarbonImmutable::now()->getTimestamp();

        if ((int) $session->get($key) > $now) {
            return true;
        }

        $until = (int) $this->store()->get($this->cacheKey($user));

        if ($until > $now) {
            $session->put($key, $until);

            return true;
        }

        $session->forget($key);

        return false;
    }

    /**
     * Hide the nudge for this user until the next midnight in $timezone (the
     * browser's; app.timezone when it's missing or unknown).
     *
     * @return CarbonImmutable the instant it shows again, in app.timezone
     */
    public function dismiss(Session $session, Authenticatable $user, mixed $timezone): CarbonImmutable
    {
        $until = self::nextMidnight(CarbonImmutable::now(), $this->timezone($timezone));

        $this->store()->put($this->cacheKey($user), $until->getTimestamp(), $until);
        $session->put($this->sessionKey($user), $until->getTimestamp());

        return $until->setTimezone($this->appTimezone());
    }

    /** When the nudge shows again for this user (from the cache), in app.timezone, or null. */
    public function dismissedUntil(Authenticatable $user): ?CarbonImmutable
    {
        $until = (int) $this->store()->get($this->cacheKey($user));

        return $until > CarbonImmutable::now()->getTimestamp()
            ? CarbonImmutable::createFromTimestamp($until, $this->appTimezone())
            : null;
    }

    /**
     * The start of the next day in $timezone, as an instant. A midnight the
     * clocks skip (summer time starting at 00:00) becomes the day's first
     * real minute, e.g. 01:00.
     */
    public static function nextMidnight(CarbonInterface $now, string $timezone): CarbonImmutable
    {
        $midnight = CarbonImmutable::createFromTimestamp($now->getTimestamp(), $timezone)->addDay()->startOfDay();

        return self::cap($now, $midnight);
    }

    /** $until if it is ahead of $now and at most MAX_HOURS away, otherwise $now + MAX_HOURS. */
    public static function cap(CarbonInterface $now, CarbonInterface $until): CarbonImmutable
    {
        $max = $now->getTimestamp() + self::MAX_HOURS * 3600;
        $at = $until->getTimestamp();

        return CarbonImmutable::createFromTimestamp($at > $now->getTimestamp() && $at <= $max ? $at : $max, $until->getTimezone());
    }

    /** A known timezone identifier (old names such as Asia/Calcutta included), else app.timezone. */
    public function timezone(mixed $timezone): string
    {
        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            ? $timezone
            : $this->appTimezone();
    }

    private function appTimezone(): string
    {
        return (string) $this->config->get('app.timezone');
    }

    private function sessionKey(Authenticatable $user): string
    {
        return self::SESSION_PREFIX.'.'.$user->getAuthIdentifier();
    }

    private function cacheKey(Authenticatable $user): string
    {
        return CacheKey::for('nudge-dismissed', CacheKey::user($user));
    }

    private function store(): Cache
    {
        return $this->cache->store($this->config->get('mfa.cache.store'));
    }
}
