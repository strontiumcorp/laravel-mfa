<?php

namespace StrontiumCorp\LaravelMfa\Sms\Concerns;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Support\Redact;
use Throwable;

/**
 * Shared HTTP behaviour for the built-in SMS drivers.
 *
 * Short timeouts keep a failover chain well inside PHP/proxy limits. A retry
 * happens only when the request provably never reached the provider (DNS or
 * connect failure, nothing uploaded): retrying after a read timeout could
 * deliver the same message twice.
 *
 * @property-read Http $http
 * @property-read array<string, mixed> $config
 */
trait CallsProviderApi
{
    protected function request(): PendingRequest
    {
        return $this->http
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3))
            ->timeout((int) ($this->config['timeout'] ?? 5))
            ->retry(2, 200, fn (Throwable $e) => self::neverSent($e), throw: false);
    }

    /**
     * A connection error: certain only when the request never reached the
     * provider; otherwise (e.g. a read timeout) the message may have gone out.
     */
    protected static function connectionFailed(string $provider, ConnectionException $e): DeliveryFailed
    {
        $detail = 'connection: '.Redact::text($e->getMessage());

        return self::neverSent($e) ? DeliveryFailed::provider($provider, $detail) : DeliveryFailed::uncertain($provider, $detail);
    }

    /** True only when the request certainly didn't reach the provider. */
    public static function neverSent(Throwable $e): bool
    {
        $connect = $e instanceof ConnectionException ? $e->getPrevious() : $e;

        if (! $connect instanceof ConnectException) {
            return false;
        }

        // Guzzle 8: ConnectException means the connection was never
        // established (read timeouts are NetworkTimeoutException).
        if (! method_exists($connect, 'getHandlerContext')) {
            return true;
        }

        // Guzzle 7 uses ConnectException for every network error, so ask
        // curl: 6 = couldn't resolve host, 7 = couldn't connect; otherwise
        // (e.g. 28, a timeout) only if not a single byte was sent.
        $context = $connect->getHandlerContext();
        $errno = (int) ($context['errno'] ?? 0);

        return in_array($errno, [6, 7], true)
            || ($errno !== 0 && isset($context['request_size']) && (int) $context['request_size'] === 0);
    }
}
