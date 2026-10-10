<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use StrontiumCorp\LaravelMfa\Exceptions\RevocationCacheUnavailable;
use Throwable;

/**
 * When each user's verifications were last revoked (Mfa::revokeVerifications())
 * and their logins last ended (Mfa::reset()). A session verified, or logged
 * in, at or before that second must pass MFA again, or sign in again,
 * whichever session it is: other sessions can't be edited, so the gate asks
 * here on every request of a logged-in user.
 *
 * The table is the truth; the cache is read first (one read per request)
 * and filled with add() on a miss, so a stale fill never wins over a
 * revocation written meanwhile. A cache that fails falls back to the table;
 * a table that fails too throws (never let through unchecked).
 *
 * @phpstan-type Stamps array{revoked: int, logged_out: int}
 */
final class Revocations
{
    /** Request attribute holding a user's stamps for the rest of the request. */
    private const MEMO_PREFIX = 'mfa.revocations.';

    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * Revoke every verification of this user made up to now, and with
     * $logout every login too. Returns the stamp. Throws when the cache can
     * neither take the stamp nor drop its old copy: sessions would otherwise
     * keep reading "never revoked" until that copy expires, while the caller
     * believes it worked.
     */
    public function revoke(int|string $userId, bool $logout = false): int
    {
        $at = now()->getTimestamp();
        $row = ['user_id' => $userId, 'revoked_at' => $at, ...($logout ? ['logged_out_at' => $at] : [])];

        DB::table($this->table())->upsert([$row], ['user_id'], array_keys(array_diff_key($row, ['user_id' => true])));
        $stamps = $this->query($userId);

        $this->forgetMemo($userId);

        try {
            $stored = $this->store()->put($this->key($userId), $this->encode($stamps), $this->ttl());
        } catch (Throwable $e) {
            report(RevocationCacheUnavailable::wrap($e));
            $stored = false;
        }

        // The table already holds it; a dropped copy is read from there. A
        // store that can do neither (some fail without throwing) must not
        // leave the old "never revoked" readable.
        if (! $stored && ! $this->store()->forget($this->key($userId)) && $this->store()->get($this->key($userId)) !== null) {
            throw new RevocationCacheUnavailable('The MFA cache store kept an old revocation stamp; the revocation is in the table but other sessions may not see it until mfa.cache.ttl passes.');
        }

        return $at;
    }

    /**
     * The user's last revocation and logout (unix seconds), 0 for never.
     *
     * @return Stamps
     */
    public function stamps(int|string $userId): array
    {
        $request = $this->liveRequest();
        $memo = self::MEMO_PREFIX.$userId;

        if ($request?->attributes->has($memo)) {
            /** @var Stamps */
            return $request->attributes->get($memo);
        }

        try {
            $stamps = $this->decode($this->store()->get($this->key($userId)));
        } catch (Throwable $e) {
            report(RevocationCacheUnavailable::wrap($e));
            $stamps = $this->query($userId);
            $request?->attributes->set($memo, $stamps);

            return $stamps;
        }

        if ($stamps === null) {
            $stamps = $this->query($userId);
            rescue(fn () => $this->store()->add($this->key($userId), $this->encode($stamps), $this->ttl()), report: false);
        }

        $request?->attributes->set($memo, $stamps);

        return $stamps;
    }

    /**
     * A value read together with other keys (Mfa::revocations() fetches it
     * with the user's factor types in one many()), kept for this request.
     * A missing or unreadable value is ignored: stamps() reads it then.
     */
    public function prime(int|string $userId, mixed $cached): void
    {
        if (($stamps = $this->decode($cached)) !== null) {
            $this->liveRequest()?->attributes->set(self::MEMO_PREFIX.$userId, $stamps);
        }
    }

    public function cacheKey(int|string $userId): string
    {
        return $this->key($userId);
    }

    /** @return Stamps|null */
    private function decode(mixed $cached): ?array
    {
        return is_string($cached) && preg_match('/^(\d+):(\d+)$/', $cached, $m) === 1
            ? ['revoked' => (int) $m[1], 'logged_out' => (int) $m[2]]
            : null;
    }

    private function forgetMemo(int|string $userId): void
    {
        $this->liveRequest()?->attributes->remove(self::MEMO_PREFIX.$userId);
    }

    /** The request being handled, if it has a session: stamps are kept on it for its duration only. */
    private function liveRequest(): ?Request
    {
        $container = Container::getInstance();
        $request = $container->bound('request') ? $container->make('request') : null;

        return $request instanceof Request && $request->hasSession() ? $request : null;
    }

    /** Whether a verification made at $verifiedAt (unix seconds) is revoked. */
    public function revokes(int|string $userId, int $verifiedAt): bool
    {
        $at = $this->stamps($userId)['revoked'];

        return $at > 0 && $verifiedAt <= $at;
    }

    /** @return Stamps */
    private function query(int|string $userId): array
    {
        $row = DB::table($this->table())->where('user_id', $userId)->first(['revoked_at', 'logged_out_at']);

        return ['revoked' => (int) ($row->revoked_at ?? 0), 'logged_out' => (int) ($row->logged_out_at ?? 0)];
    }

    /** @param Stamps $stamps */
    private function encode(array $stamps): string
    {
        return $stamps['revoked'].':'.$stamps['logged_out'];
    }

    private function table(): string
    {
        return (string) $this->config->get('mfa.tables.revocations');
    }

    private function key(int|string $userId): string
    {
        return $this->config->get('mfa.cache.prefix').':revocations:'.$userId;
    }

    private function ttl(): int
    {
        return (int) $this->config->get('mfa.cache.ttl');
    }

    private function store(): Cache
    {
        return $this->cache->store($this->config->get('mfa.cache.store'));
    }
}
