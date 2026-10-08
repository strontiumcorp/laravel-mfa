<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;

/**
 * Builds SMS senders from config('mfa.sms.drivers').
 *
 * A driver's name selects its transport unless its config sets "transport",
 * so you can define several named drivers of the same kind — e.g. a "bd"
 * failover chain next to the default one (like Laravel's mailers).
 *
 * @method SmsSender driver(string|null $driver = null)
 */
class SmsManager extends Manager
{
    /** @var list<string> drivers being built right now (cycle detection) */
    private array $resolving = [];

    /**
     * Drivers are cheap and are deliberately not cached: each send gets a
     * sender built from current config and the current HTTP client, so
     * Http::fake() and runtime config changes always take effect.
     */
    public function driver($driver = null)
    {
        $name = $driver ?: $this->getDefaultDriver();

        if (in_array($name, $this->resolving, true)) {
            throw new InvalidArgumentException('Circular SMS driver configuration: '.implode(' → ', [...$this->resolving, $name]));
        }

        $this->resolving[] = $name;

        try {
            return $this->createDriver($name);
        } finally {
            array_pop($this->resolving);
        }
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('mfa.sms.driver');
    }

    protected function createDriver($driver)
    {
        $config = $this->driverConfig($driver);
        $transport = (string) ($config['transport'] ?? $driver);

        // Custom drivers registered via Mfa::extendSms() receive their config.
        if (isset($this->customCreators[$transport])) {
            return $this->customCreators[$transport]($this->container, $config);
        }

        $method = 'create'.Str::studly($transport).'Driver';

        if (! method_exists($this, $method)) {
            throw new InvalidArgumentException("SMS driver [{$driver}] is not supported.");
        }

        return $this->{$method}($config);
    }

    /** @param array<string, mixed> $config */
    protected function createLogDriver(array $config): LogSmsSender
    {
        return new LogSmsSender($this->container->make('log'), $config['channel'] ?? null);
    }

    /** @param array<string, mixed> $config */
    protected function createTwilioDriver(array $config): TwilioSmsSender
    {
        return new TwilioSmsSender($this->container->make(Http::class), $config);
    }

    /** @param array<string, mixed> $config */
    protected function createVonageDriver(array $config): VonageSmsSender
    {
        return new VonageSmsSender($this->container->make(Http::class), $config);
    }

    /** @param array<string, mixed> $config */
    protected function createInfobipDriver(array $config): InfobipSmsSender
    {
        return new InfobipSmsSender($this->container->make(Http::class), $config);
    }

    /** @param array<string, mixed> $config */
    protected function createSnsDriver(array $config): SnsSmsSender
    {
        return new SnsSmsSender($this->container->make(Http::class), $config);
    }

    /** @param array<string, mixed> $config */
    protected function createFailoverDriver(array $config): FailoverSmsSender
    {
        $senders = [];
        foreach ((array) ($config['drivers'] ?? []) as $name) {
            $senders[(string) $name] = $this->driver((string) $name);
        }

        return new FailoverSmsSender($senders, $this->container->make(Dispatcher::class));
    }

    /** @param array<string, mixed> $config */
    protected function createRoutingDriver(array $config): RoutingSmsSender
    {
        if (empty($config['default'])) {
            throw new InvalidArgumentException('The routing SMS driver needs a [default] driver.');
        }

        $routes = [];
        foreach ((array) ($config['routes'] ?? []) as $prefix => $name) {
            $routes[(string) $prefix] = $this->driver((string) $name);
        }

        return new RoutingSmsSender($routes, $this->driver((string) $config['default']));
    }

    /** @return array<string, mixed> */
    private function driverConfig(string $driver): array
    {
        return (array) $this->config->get("mfa.sms.drivers.{$driver}", []);
    }
}
