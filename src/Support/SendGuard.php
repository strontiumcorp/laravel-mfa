<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\SendingCircuitTripped;
use StrontiumCorp\LaravelMfa\Events\SuspiciousCodeRequests;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;

/**
 * Every limit on sending a code, in one place.
 *
 * Two budgets are kept strictly apart so the protection against bombing can't
 * be turned into a lock-out:
 *
 *  - Unconfirmed destinations (enrollment) — anyone with any account can
 *    trigger these to any number/address, so they are tight: N messages per
 *    destination per day across ALL accounts, a cap on new destinations per
 *    account and per IP (IPv6 per /64), and an app-wide circuit breaker.
 *  - Confirmed destinations (login) — only an account that proved ownership
 *    can use these, so a stranger can never drain them. Per-account hourly
 *    cap (plus the per-factor cooldown curve in OtpStore) and an app-wide
 *    hourly cap against pumping through many accounts (off with null/0).
 *    No per-IP cap, so many users behind one NAT are fine.
 *
 * Both budgets also count toward a per-account daily cap for each factor type
 * (factors.{type}.send_per_day, off with null/0), so someone with the password
 * and the inbox/phone can't run up costs by logging in again and again.
 *
 * Called only when a code will really be sent (after the cooldown check), so
 * rejected requests never consume budget.
 */
final class SendGuard
{
    private const HOUR = 3600;

    private const DAY = 86400;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Cache $cache,
        private readonly Dispatcher $events,
    ) {}

    /**
     * null = allowed (and counted); otherwise the refusal.
     *
     * Every counter this send touches is counted atomically (hit() is an
     * atomic increment, so concurrent requests can't all slip under a limit).
     * If any limit refuses, everything counted so far for this attempt is
     * rolled back, so a refused request never consumes any budget.
     */
    public function attempt(MfaFactor $factor, ?string $ip): ?VerificationResult
    {
        $user = $factor->user;
        $confirmed = $factor->isConfirmed();
        // Equivalent mutant(s): send paths only reach here with a destination.
        $destination = (string) $factor->destination; // @pest-mutate-ignore: RemoveStringCast
        $undo = [];

        $counters = [];
        if ($user !== null) {
            $counters[] = [CacheKey::for('send', CacheKey::user($user)), $this->limit('send_per_hour'), self::HOUR, FailureReason::RateLimited, 'account'];

            // Every code of this type for the account (login and enrollment),
            // over the RateLimiter's 24h window from the first send, like the
            // hourly cap. Not counted at all when off. On Laravel 11.22 a
            // rollback to 1 re-puts the counter with a fresh 24h, so that one
            // count can outlive the window: stricter by one, never looser.
            $perDay = $this->perDay($factor);
            if ($perDay > 0) {
                $subject = CacheKey::user($user).'|'.$factor->type->value;
                $counters[] = [CacheKey::for('send-daily', $subject), $perDay, self::DAY, FailureReason::DailyLimit, 'account_daily'];
            }
        }
        if (! $confirmed) {
            $counters[] = [CacheKey::for('send-unconfirmed', $destination), $this->limit('unconfirmed_per_destination_per_day'), self::DAY, FailureReason::DestinationLimit, 'destination'];
            $counters[] = [CacheKey::for('send-unconfirmed', '*global*'), $this->limit('unconfirmed_global_per_hour'), self::HOUR, FailureReason::SendingPaused, 'global'];
        } elseif ($this->limit('confirmed_global_per_hour') > 0) {
            $counters[] = [CacheKey::for('send-confirmed', '*global*'), $this->limit('confirmed_global_per_hour'), self::HOUR, FailureReason::SendingPaused, 'confirmed_global'];
        }

        foreach ($counters as [$key, $limit, $decay, $reason, $scope]) {
            $undo[] = fn () => $this->limiter->decrement($key, $decay);

            if ($this->limiter->hit($key, $decay) > $limit) {
                return $this->rollbackAndRefuse($undo, $factor, $key, $reason, $scope);
            }
        }

        // Distinct new destinations (retrying the same one is free).
        $distinct = [];
        if (! $confirmed && $user !== null) {
            $distinct[] = ['account-destinations', CacheKey::user($user), 'new_destinations_per_account_per_day', self::DAY, 'account_destinations'];
        }
        if (! $confirmed && ($bucket = self::ipBucket($ip)) !== null) {
            $distinct[] = ['ip-destinations', $bucket, 'new_destinations_per_ip_per_hour', self::HOUR, 'ip'];
        }

        foreach ($distinct as [$bucket, $subject, $limit, $decay, $scope]) {
            $counter = CacheKey::for($bucket, $subject);
            $marker = CacheKey::for("{$bucket}-seen", "{$subject}|{$destination}");

            if ($this->cache->has($marker)) {
                continue; // this destination is already counted here
            }

            // Equivalent mutant: the marker's value is never read, only its presence.
            $this->cache->put($marker, true, $decay); // @pest-mutate-ignore: TrueToFalse
            $undo[] = function () use ($marker, $counter, $decay) {
                $this->cache->forget($marker);
                $this->limiter->decrement($counter, $decay);
            };

            if ($this->limiter->hit($counter, $decay) > $this->limit($limit)) {
                return $this->rollbackAndRefuse($undo, $factor, $counter, FailureReason::RateLimited, $scope);
            }
        }

        return null;
    }

    /**
     * Group IPv6 clients by their /64 — one subscriber gets a whole /64, so
     * per-address limits would be trivial to rotate around.
     */
    public static function ipBucket(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        // Equivalent mutant(s): strlen(false) is 0, so invalid input still falls through to the literal bucket.
        if (str_contains($ip, ':') && ($packed = @inet_pton($ip)) !== false && strlen($packed) === 16) { // @pest-mutate-ignore: FalseToTrue
            // Equivalent mutants: the prefixes only keep the two families apart,
            // and a 16-hex-digit /64 can never equal a dotted IPv4 string.
            return 'v6:'.bin2hex(substr($packed, 0, 8)); // @pest-mutate-ignore: ConcatRemoveLeft,ConcatSwitchSides
        }

        return 'v4:'.$ip; // @pest-mutate-ignore: ConcatRemoveLeft,ConcatSwitchSides
    }

    /** @param list<callable(): mixed> $undo */
    private function rollbackAndRefuse(array $undo, MfaFactor $factor, string $key, FailureReason $reason, string $scope): VerificationResult
    {
        foreach ($undo as $revert) {
            $revert();
        }

        return $this->refuse($factor, $key, $reason, $scope);
    }

    private function refuse(MfaFactor $factor, string $key, FailureReason $reason, string $scope): VerificationResult
    {
        $retryAfter = $this->limiter->availableIn($key);

        // An app-wide cap: alert once per window for each (the flag expires
        // when the window frees up, so the next window that trips alerts too).
        $breaker = match ($scope) {
            'global' => ['mfa:circuit-tripped', 'unconfirmed_global_per_hour', 'unconfirmed'],
            'confirmed_global' => ['mfa:circuit-tripped:confirmed', 'confirmed_global_per_hour', 'confirmed'],
            default => null,
        };

        // Equivalent mutant: the flag's value is never read, only its presence.
        if ($breaker !== null && $this->cache->add($breaker[0], true, max(1, $retryAfter))) { // @pest-mutate-ignore: TrueToFalse
            $this->events->dispatch(new SendingCircuitTripped(null, $factor->type, $reason, ['limit' => $this->limit($breaker[1]), 'scope' => $breaker[2]]));
        }

        if (in_array($scope, ['account', 'account_daily'], true) && $factor->isConfirmed()) {
            $this->warnOnce($factor, 'send_cap_reached');
        }

        return VerificationResult::failure($reason, ['scope' => $scope, 'retry_after' => $retryAfter]);
    }

    /**
     * At most one warning per factor per hour.
     *
     * @param  array<string, mixed>  $context
     */
    public function warnOnce(MfaFactor $factor, string $reason, array $context = []): void
    {
        // Equivalent mutant: the flag's value is never read, only its presence.
        // Equivalent mutant(s): the key is an int primary key, stringified the same way.
        if ($this->cache->add(CacheKey::for('suspicious', (string) $factor->getKey()), true, self::HOUR)) { // @pest-mutate-ignore: TrueToFalse,RemoveStringCast
            $this->events->dispatch(new SuspiciousCodeRequests($factor->user, $factor->type, null, ['reason' => $reason, 'factor_id' => $factor->getKey(), ...$context]));
        }
    }

    /** factors.{type}.send_per_day; null or 0 = off. */
    private function perDay(MfaFactor $factor): int
    {
        // Equivalent mutant: limits are ints (or numeric strings from env).
        return (int) config("mfa.factors.{$factor->type->value}.send_per_day"); // @pest-mutate-ignore: RemoveIntegerCast
    }

    private function limit(string $name): int
    {
        // Equivalent mutant: limits are ints (or numeric strings from env,
        // which PHP compares numerically).
        return (int) config("mfa.rate_limit.{$name}"); // @pest-mutate-ignore: RemoveIntegerCast
    }
}
