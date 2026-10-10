<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Session\Session;
use StrontiumCorp\LaravelMfa\Contracts\LifetimePolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Mfa;

/**
 * How long one passed challenge lasts (config mfa.lifetime).
 *
 * A verification gets a profile when it succeeds; its deadlines are copied
 * into the session, so the gate decides from the session alone (no query).
 * Expiry is always computed from timestamps, never stored as a flag, so a
 * concurrent request writing back an older copy of the session can't bring
 * an expired verification back.
 *
 * @phpstan-type Profile array{absolute: int|null, idle: int|null, reminder: int|null, grace: int, on_expiry: 'challenge'|'logout'}
 * @phpstan-type Entry array{profile: string, started: int, until: int|null, idle: int|null, grace: int, remind_at: int|null, on_expiry: 'challenge'|'logout'}
 */
final class VerificationLifetime
{
    /** Session: the verification's profile and deadlines, per guard and user. */
    public const SESSION_KEY = 'mfa.lifetime';

    /** Session: the last activity (unix seconds), per guard and user. */
    public const SEEN_KEY = 'mfa.seen';

    public const DEFAULT_PROFILE = 'default';

    public const ENFORCED_PROFILE = 'enforced';

    public function __construct(private readonly Config $config) {}

    /**
     * The profile name for this user: lifetime.policy, else enforced/default.
     * Pass $enforced when the caller already asked Mfa::isEnforced().
     */
    public function profileFor(MultiFactorAuthenticatable $user, ?bool $enforced = null): string
    {
        $policy = $this->config->get('mfa.lifetime.policy');

        if (is_string($policy) && $policy !== '') {
            /** @var LifetimePolicy $instance */
            $instance = Container::getInstance()->make($policy);
            $name = $instance->profile($user);
        } else {
            $name = ($enforced ?? $this->mfa()->isEnforced($user)) ? self::ENFORCED_PROFILE : self::DEFAULT_PROFILE;
        }

        return is_array($this->config->get("mfa.lifetime.profiles.{$name}")) ? $name : self::DEFAULT_PROFILE;
    }

    /**
     * A profile's settings in minutes (null = off), normalised: env values
     * arrive as strings, and 0 or a negative number means off.
     *
     * @return Profile
     */
    public function profile(string $name): array
    {
        $raw = (array) ($this->config->get("mfa.lifetime.profiles.{$name}") ?? []);
        $minutes = fn (string $key): ?int => is_numeric($raw[$key] ?? null) && (int) $raw[$key] > 0 ? (int) $raw[$key] : null;

        return [
            'absolute' => $minutes('absolute'),
            'idle' => $minutes('idle'),
            'reminder' => $minutes('reminder'),
            'grace' => $minutes('grace') ?? 0,
            'on_expiry' => ($raw['on_expiry'] ?? null) === 'logout' ? 'logout' : 'challenge',
        ];
    }

    /**
     * Whether this user's verifications end on their own (a fixed window or
     * an idle timeout), so a browser can't be trusted: its cookie would
     * verify them again the moment either ends.
     */
    public function hasWindow(MultiFactorAuthenticatable $user, ?bool $enforced = null): bool
    {
        $profile = $this->profile($this->profileFor($user, $enforced));

        return $profile['absolute'] !== null || $profile['idle'] !== null;
    }

    /**
     * Start a verification's lifetime at $startedAt (now, or when an older
     * session was verified, for one verified before lifetimes existed).
     *
     * @return Entry
     */
    public function start(Session $session, string $guard, MultiFactorAuthenticatable $user, ?int $startedAt = null): array
    {
        $now = now()->getTimestamp();
        $started = min($startedAt ?? $now, $now);
        $name = $this->profileFor($user);
        $profile = $this->profile($name);
        $until = $profile['absolute'] === null ? null : $started + $profile['absolute'] * 60;

        $entry = [
            'profile' => $name,
            'started' => $started,
            'until' => $until,
            'idle' => $profile['idle'] === null ? null : $profile['idle'] * 60,
            'grace' => $until === null ? 0 : $profile['grace'] * 60,
            'remind_at' => $until === null || $profile['reminder'] === null ? null : max($started, $until - $profile['reminder'] * 60),
            'on_expiry' => $profile['on_expiry'],
        ];

        // Only what is set: most verifications have no window (a smaller session).
        $session->put($this->key(self::SESSION_KEY, $guard, $user->getAuthIdentifier()), array_filter(
            $entry,
            fn ($value, $key) => in_array($key, ['profile', 'started'], true) || ($value !== null && $value !== 0 && $value !== 'challenge'),
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($entry['idle'] !== null) {
            $session->put($this->key(self::SEEN_KEY, $guard, $user->getAuthIdentifier()), $now);
        } else {
            $session->forget($this->key(self::SEEN_KEY, $guard, $user->getAuthIdentifier()));
        }

        return $entry;
    }

    /**
     * An impersonation's lifetime (Mfa::grantForImpersonation()): the
     * target's profile from now, but never past the admin's own window or
     * idle timeout, and it always logs out when it ends (the admin can't
     * pass the target's challenge).
     *
     * @param  Entry|null  $impersonator  the admin's own lifetime entry, when they have one
     * @return Entry
     */
    public function startImpersonation(Session $session, string $guard, MultiFactorAuthenticatable $target, ?array $impersonator): array
    {
        $entry = $this->start($session, $guard, $target);

        if ($impersonator !== null) {
            if ($impersonator['until'] !== null && ($entry['until'] === null || $impersonator['until'] < $entry['until'])) {
                $entry['until'] = $impersonator['until'];
                $entry['grace'] = $impersonator['grace'];
                $entry['remind_at'] = $impersonator['remind_at'];
            }
            $idles = array_filter([$entry['idle'], $impersonator['idle']], fn ($v) => $v !== null);
            $entry['idle'] = $idles === [] ? null : min($idles);
        }

        $entry['on_expiry'] = 'logout';
        $session->put($this->key(self::SESSION_KEY, $guard, $target->getAuthIdentifier()), array_filter(
            $entry,
            fn ($value, $key) => in_array($key, ['profile', 'started'], true) || ($value !== null && $value !== 0),
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($entry['idle'] !== null) {
            $session->put($this->key(self::SEEN_KEY, $guard, $target->getAuthIdentifier()), now()->getTimestamp());
        }

        return $entry;
    }

    /** @return Entry|null */
    public function entry(Session $session, string $guard, int|string $id): ?array
    {
        $entry = $session->get($this->key(self::SESSION_KEY, $guard, $id));

        if (! is_array($entry) || ! isset($entry['started'])) {
            return null;
        }

        // Stored without the fields that are off (start()).
        return [
            'profile' => (string) ($entry['profile'] ?? self::DEFAULT_PROFILE),
            'started' => (int) $entry['started'],
            'until' => isset($entry['until']) ? (int) $entry['until'] : null,
            'idle' => isset($entry['idle']) ? (int) $entry['idle'] : null,
            'grace' => (int) ($entry['grace'] ?? 0),
            'remind_at' => isset($entry['remind_at']) ? (int) $entry['remind_at'] : null,
            'on_expiry' => ($entry['on_expiry'] ?? null) === 'logout' ? 'logout' : 'challenge',
        ];
    }

    /**
     * Why this verification has ended ("absolute" | "idle"), "grace" while
     * the absolute window has run out but its grace hasn't, or null.
     *
     * @param  Entry  $entry
     */
    public function status(Session $session, string $guard, int|string $id, array $entry): ?string
    {
        $now = now()->getTimestamp();
        $until = $entry['until'];

        if ($until !== null && $now >= $until + $entry['grace']) {
            return 'absolute';
        }

        if ($entry['idle'] !== null && $now >= $this->lastSeen($session, $guard, $id, $entry) + $entry['idle']) {
            return 'idle';
        }

        return $until !== null && $now >= $until ? 'grace' : null;
    }

    /** Record activity for the idle timeout. */
    public function touch(Session $session, string $guard, int|string $id): void
    {
        $session->put($this->key(self::SEEN_KEY, $guard, $id), now()->getTimestamp());
    }

    /** @param Entry $entry */
    public function lastSeen(Session $session, string $guard, int|string $id, array $entry): int
    {
        $seen = $session->get($this->key(self::SEEN_KEY, $guard, $id));

        return is_numeric($seen) ? max((int) $seen, $entry['started']) : $entry['started'];
    }

    /** Forget a verification's lifetime (with the verification itself, by the caller). */
    public function forget(Session $session, string $guard, int|string $id): void
    {
        $session->forget([$this->key(self::SESSION_KEY, $guard, $id), $this->key(self::SEEN_KEY, $guard, $id)]);
    }

    /**
     * The verification's deadlines for the frontend, null when it has none.
     *
     * @param  Entry  $entry
     *                        `now` is the server's clock, so a page can correct for a client clock that is off.
     * @return array{profile: string, now: string, expiresAt: string|null, remindAt: string|null, graceUntil: string|null, idleSeconds: int|null, idleExpiresAt: string|null}|null
     */
    public function describe(Session $session, string $guard, int|string $id, array $entry): ?array
    {
        if ($entry['until'] === null && $entry['idle'] === null) {
            return null;
        }

        $iso = fn (?int $ts): ?string => $ts === null ? null : CarbonImmutable::createFromTimestamp($ts)->toIso8601String();

        return [
            'profile' => $entry['profile'],
            'now' => (string) $iso(now()->getTimestamp()),
            'expiresAt' => $iso($entry['until']),
            'remindAt' => $iso($entry['remind_at']),
            'graceUntil' => $entry['until'] === null || $entry['grace'] === 0 ? null : $iso($entry['until'] + $entry['grace']),
            'idleSeconds' => $entry['idle'],
            'idleExpiresAt' => $entry['idle'] === null ? null : $iso($this->lastSeen($session, $guard, $id, $entry) + $entry['idle']),
        ];
    }

    private function key(string $prefix, string $guard, int|string $id): string
    {
        return $prefix.'.'.$guard.'.'.$id;
    }

    private function mfa(): Mfa
    {
        return Container::getInstance()->make(Mfa::class);
    }
}
