<?php

namespace StrontiumCorp\LaravelMfa\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Http\Middleware\EnsureMfaVerified;
use StrontiumCorp\LaravelMfa\Mfa;
use Throwable;

/**
 * Verifies an application's integration. Exits non-zero on any failure so it
 * can gate deploys / CI.
 */
class DoctorCommand extends Command
{
    protected $signature = 'mfa:doctor';

    protected $description = 'Check that MFA is correctly integrated and configured';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(Mfa $mfa, Router $router): int
    {
        $this->components->info('MFA integration check');

        $this->check('APP_KEY is set (codes are HMAC-keyed from it)', filled(config('app.key')));

        foreach (config('mfa.tables') as $name => $table) {
            $this->check("Table [{$table}] exists", $this->safely(fn () => Schema::hasTable($table)), 'Run php artisan migrate');
        }

        foreach ($mfa->guards() as $guard) {
            $provider = config("auth.guards.{$guard}.provider");
            $model = config("auth.providers.{$provider}.model");
            $this->check(
                "Guard [{$guard}] model implements MultiFactorAuthenticatable",
                is_string($model) && is_subclass_of($model, MultiFactorAuthenticatable::class),
                'Add `implements MultiFactorAuthenticatable` + `use HasMultiFactorAuthentication` to '.($model ?: 'your user model'),
            );
        }

        // Ask the HTTP kernel (resolving it applies our append) — the router
        // alone is not populated yet in a console process.
        $kernel = $this->laravel->make(Kernel::class);
        $web = method_exists($kernel, 'getMiddlewareGroups')
            ? ($kernel->getMiddlewareGroups()['web'] ?? [])
            : ($router->getMiddlewareGroups()['web'] ?? []);
        if (config('mfa.middleware.append_to_web_group')) {
            $this->check('Middleware is in the web group', in_array(EnsureMfaVerified::class, $web, true));
        } else {
            $this->warn_('Middleware is NOT appended to the web group — every protected route must use the `mfa` middleware explicitly');
        }

        $this->check('At least one factor is enabled', $mfa->enabledTypes() !== []);

        if ($mfa->isTypeEnabled(FactorType::Sms)) {
            $driver = (string) config('mfa.sms.driver');
            $problems = $this->smsDriverProblems($driver);
            $this->check("SMS driver [{$driver}] is configured", $problems === []);
            foreach ($problems as $problem) {
                $this->line("    <fg=red>•</> {$problem}");   // one per line: chains can have several
            }
            if ($driver === 'log' && app()->isProduction()) {
                $this->warn_('SMS driver is "log" in production — codes will not be delivered');
            }
            if (config('mfa.factors.sms.allowed_calling_codes') === []) {
                $this->warn_('SMS allowed_calling_codes is empty — any country can trigger SMS (toll-fraud risk)');
            }
        }

        if ($mfa->isTypeEnabled(FactorType::Email) && in_array(config('mail.default'), ['log', 'array'], true) && app()->isProduction()) {
            $this->warn_('Mailer is "'.config('mail.default').'" in production — email codes will not be delivered');
        }

        if (in_array(config('session.driver'), ['array'], true)) {
            $this->check('Session driver persists between requests', false, 'SESSION_DRIVER=array cannot hold MFA state');
        } elseif (in_array(config('session.driver'), ['file', 'cookie'], true)) {
            $this->warn_('Session driver is "'.config('session.driver').'" — fine for one server; use database/redis behind a load balancer');
        }

        $cacheStore = config('mfa.cache.store') ?? config('cache.default');
        if (in_array(config("cache.stores.{$cacheStore}.driver"), ['file', 'array'], true)) {
            $this->warn_("Cache store [{$cacheStore}] is not shared — rate limits and factor cache are per-server");
        }

        if ($connection = config('mfa.delivery.queue_connection')) {
            $this->check("Delivery queue connection [{$connection}] exists", config("queue.connections.{$connection}") !== null);
        }

        if ($policy = config('mfa.enforce')) {
            $this->check("Enforcement policy [{$policy}] implements EnforcementPolicy", is_string($policy) && is_subclass_of($policy, EnforcementPolicy::class));
        }

        if (config('mfa.ui.driver') === 'inertia') {
            $this->check('inertiajs/inertia-laravel is installed (ui.driver = inertia)', class_exists(Inertia::class));
        }

        $this->checkTrustedProxies();

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error("{$this->failures} check(s) failed, {$this->warnings} warning(s).");

            return self::FAILURE;
        }

        $this->components->info("All checks passed ({$this->warnings} warning(s)).");

        return self::SUCCESS;
    }

    /**
     * Missing settings for an SMS driver, following failover/routing chains.
     *
     * @param  list<string>  $seen
     * @return list<string>
     */
    private function smsDriverProblems(string $driver, array $seen = []): array
    {
        if (in_array($driver, $seen, true)) {
            return ['circular reference '.implode(' → ', [...$seen, $driver])];
        }

        $config = (array) config("mfa.sms.drivers.{$driver}", []);
        $missing = fn (string ...$keys) => array_map(
            fn ($key) => "[{$driver}] missing {$key}",
            array_values(array_filter($keys, fn ($key) => blank($config[$key] ?? null))),
        );

        return match ((string) ($config['transport'] ?? $driver)) {
            'log' => [],
            'twilio' => [...$missing('sid', 'token'), ...(blank($config['from'] ?? null) && blank($config['messaging_service_sid'] ?? null) ? ["[{$driver}] missing from or messaging_service_sid"] : [])],
            'vonage' => $missing('key', 'secret', 'from'),
            'infobip' => $missing('api_key', 'from'),
            'sns' => $missing('key', 'secret', 'region'),
            'failover' => blank($config['drivers'] ?? null)
                ? ["[{$driver}] has no drivers"]
                : array_merge(...array_map(fn ($name) => $this->smsDriverProblems((string) $name, [...$seen, $driver]), (array) $config['drivers'])),
            'routing' => blank($config['default'] ?? null)
                ? ["[{$driver}] has no default"]
                : array_merge(
                    $this->smsDriverProblems((string) $config['default'], [...$seen, $driver]),
                    ...array_map(fn ($name) => $this->smsDriverProblems((string) $name, [...$seen, $driver]), array_values((array) ($config['routes'] ?? []))),
                ),
            default => [], // custom driver registered via Mfa::extendSms()
        };
    }

    /**
     * Per-IP send limits are only as good as the client IP Laravel sees.
     */
    private function checkTrustedProxies(): void
    {
        $proxies = $this->trustedProxies();

        if ($proxies === null || $proxies === [] || $proxies === '') {
            $this->warn_('No trusted proxies configured — behind a load balancer/CDN every client shares its IP and per-IP limits hit everyone together. Configure trustProxies(at: …) if you use one');
        } elseif (in_array($proxies, ['*', '**'], true) || $proxies === ['*'] || $proxies === ['**']) {
            $this->warn_('Trusts all proxies (\'*\') — per-IP limits are reliable only if the app is reachable solely through exactly one proxy layer; otherwise clients can spoof X-Forwarded-For');
        } else {
            $this->components->twoColumnDetail('Trusted proxies configured', '<fg=green;options=bold>OK</>');
        }
    }

    /** @return array<int, string>|string|null */
    private function trustedProxies(): array|string|null
    {
        $global = (new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies'))->getValue();

        if ($global !== null) {
            return $global;
        }

        // Kernel-style apps (e.g. upgraded from Laravel 10) configure a subclass.
        $appMiddleware = $this->laravel->getNamespace().'Http\\Middleware\\TrustProxies';

        return class_exists($appMiddleware)
            ? ((new \ReflectionClass($appMiddleware))->getDefaultProperties()['proxies'] ?? null)
            : null;
    }

    private function check(string $label, bool $passed, ?string $hint = null): void
    {
        $passed ? $this->components->twoColumnDetail($label, '<fg=green;options=bold>OK</>')
            : $this->components->twoColumnDetail($label.($hint ? " <fg=gray>— {$hint}</>" : ''), '<fg=red;options=bold>FAIL</>');

        $this->failures += $passed ? 0 : 1;
    }

    private function warn_(string $message): void
    {
        $this->components->twoColumnDetail($message, '<fg=yellow;options=bold>WARN</>');
        $this->warnings++;
    }

    private function safely(callable $callback): bool
    {
        try {
            return (bool) $callback();
        } catch (Throwable) {
            return false;
        }
    }
}
