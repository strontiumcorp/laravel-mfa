<?php

namespace StrontiumCorp\LaravelMfa\Sms;

use StrontiumCorp\LaravelMfa\Contracts\SmsSender;

/**
 * Picks a sender by the longest matching number prefix (country code, or
 * country + area code), falling back to a default. Routes can point at any
 * driver, including a named failover chain.
 */
final class RoutingSmsSender implements SmsSender
{
    /** @param array<string|int, SmsSender> $routes digit prefix (with or without "+") => sender */
    public function __construct(
        private readonly array $routes,
        private readonly SmsSender $default,
    ) {}

    public function send(string $to, string $message): void
    {
        $this->senderFor($to)->send($to, $message);
    }

    public function senderFor(string $to): SmsSender
    {
        $digits = ltrim($to, '+');
        $best = null;

        foreach (array_keys($this->routes) as $prefix) {
            $prefix = ltrim((string) $prefix, '+');

            if (str_starts_with($digits, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? $this->default : ($this->routes[$best] ?? $this->routes['+'.$best]);
    }
}
