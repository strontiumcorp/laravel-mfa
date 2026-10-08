<?php

namespace StrontiumCorp\LaravelMfa;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Contracts\Factor;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Factors\EmailOtpFactor;
use StrontiumCorp\LaravelMfa\Factors\SmsOtpFactor;
use StrontiumCorp\LaravelMfa\Factors\TotpFactor;
use StrontiumCorp\LaravelMfa\Support\OtpStore;
use StrontiumCorp\LaravelMfa\Support\SendGuard;

/**
 * Resolves factor drivers. Drivers are stateless (config + services only),
 * so caching them is safe under Octane.
 *
 * @method Factor driver(string|null $driver = null)
 */
class FactorManager extends Manager
{
    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException('A factor type must be specified.');
    }

    public function factor(FactorType|string $type): Factor
    {
        return $this->driver($type instanceof FactorType ? $type->value : $type);
    }

    protected function createTotpDriver(): TotpFactor
    {
        return new TotpFactor(new Google2FA, (array) $this->config->get('mfa.factors.totp'));
    }

    protected function createEmailDriver(): EmailOtpFactor
    {
        return new EmailOtpFactor(...$this->otpDependencies('email'));
    }

    protected function createSmsDriver(): SmsOtpFactor
    {
        return new SmsOtpFactor(...$this->otpDependencies('sms'));
    }

    /** @return array<string, mixed> */
    private function otpDependencies(string $type): array
    {
        return [
            'store' => $this->container->make(OtpStore::class),
            'guard' => $this->container->make(SendGuard::class),
            'bus' => $this->container->make(Dispatcher::class),
            'events' => $this->container->make(Events::class),
            'config' => (array) $this->config->get("mfa.factors.{$type}", []),
            'delivery' => (array) $this->config->get('mfa.delivery'),
        ];
    }
}
