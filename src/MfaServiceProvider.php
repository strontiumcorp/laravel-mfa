<?php

namespace StrontiumCorp\LaravelMfa;

use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Http\Middleware\EnsureMfaVerified;
use StrontiumCorp\LaravelMfa\Listeners\ForgetMfaStateOnLogout;
use StrontiumCorp\LaravelMfa\Listeners\ForgetTrustedBrowsers;
use StrontiumCorp\LaravelMfa\Listeners\LogMfaActivity;
use StrontiumCorp\LaravelMfa\Listeners\NotifyAccountOwner;
use StrontiumCorp\LaravelMfa\Listeners\RecordMfaMetrics;
use StrontiumCorp\LaravelMfa\Listeners\WriteMfaAuditLog;
use StrontiumCorp\LaravelMfa\Metrics\LogMetricsRecorder;
use StrontiumCorp\LaravelMfa\Metrics\NullMetricsRecorder;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaOtpCode;
use StrontiumCorp\LaravelMfa\Models\MfaTrustedBrowser;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;
use StrontiumCorp\LaravelMfa\Support\CodeHasher;
use StrontiumCorp\LaravelMfa\Support\ConfigMerge;
use StrontiumCorp\LaravelMfa\Support\RandomCodeGenerator;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

class MfaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfig();

        // All singletons below are stateless (config + other services only),
        // which keeps them safe across requests under Octane.
        $this->app->singleton(Mfa::class);
        $this->app->alias(Mfa::class, 'mfa');
        $this->app->singleton(FactorManager::class, fn (Application $app) => new FactorManager($app));
        $this->app->singleton(SmsManager::class, fn (Application $app) => new SmsManager($app));

        $this->app->bind(SmsSender::class, fn (Application $app) => $app->make(SmsManager::class)->driver());
        $this->app->bindIf(CodeGenerator::class, RandomCodeGenerator::class);
        $this->app->singleton(CodeHasher::class, fn (Application $app) => CodeHasher::fromConfig($app['config']));

        $this->app->bind(RecoveryCodes::class, fn (Application $app) => new RecoveryCodes(
            $app->make(CodeGenerator::class),
            $app->make(CodeHasher::class),
            (int) $app['config']->get('mfa.recovery_codes.count'),
        ));

        $this->app->singleton(MetricsRecorder::class, function (Application $app) {
            return match ($driver = (string) $app['config']->get('mfa.observability.metrics.driver')) {
                'null', '' => new NullMetricsRecorder,
                'log' => new LogMetricsRecorder($app->make('log'), $app['config']->get('mfa.observability.log.channel')),
                default => $app->make($driver),
            };
        });
    }

    /**
     * Deep merge (see ConfigMerge) instead of mergeConfigFrom(), so apps with
     * an older published config still get newly added nested keys.
     */
    private function mergeConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return; // `config:cache` already stored the merged result.
        }

        $config = $this->app->make('config');
        $config->set('mfa', ConfigMerge::merge(require __DIR__.'/../config/mfa.php', (array) $config->get('mfa', [])));
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMigrations();
        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerObservability();
        $this->registerCommands();
        $this->registerPruning();
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/mfa.php' => config_path('mfa.php')], 'mfa-config');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'mfa-migrations');
        // UI stubs are published by `php artisan mfa:install`, which detects
        // the app's pages directory (Pages/ vs pages/).
    }

    private function registerMigrations(): void
    {
        if ($this->app->runningInConsole() && Mfa::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('mfa', EnsureMfaVerified::class);

        // Before route model binding, wherever the gate is attached (web
        // group or the "mfa" alias): otherwise an unverified user gets a 404
        // for a missing record and the challenge for an existing one, and the
        // app's binding code runs for them. The priority list already orders
        // StartSession and auth (AuthenticatesRequests) before
        // SubstituteBindings, so they still run first.
        $this->callAfterResolving(HttpKernel::class, function ($kernel) {
            if ($kernel instanceof FoundationHttpKernel) {
                $kernel->setMiddlewarePriority(self::priorityWithGate($kernel->getMiddlewarePriority()));
            }
        });

        if (! config('mfa.middleware.append_to_web_group')) {
            return;
        }

        // Append through the HTTP kernel, not the router: the kernel re-syncs
        // its groups onto the router whenever it is (re)configured, which
        // would silently drop a router-only push depending on boot order.
        // Runs now if the kernel is already resolved, otherwise on resolve —
        // after bootstrap/app.php's withMiddleware() callback.
        $this->callAfterResolving(HttpKernel::class, function ($kernel) {
            if ($kernel instanceof FoundationHttpKernel) {
                $kernel->appendMiddlewareToGroup('web', EnsureMfaVerified::class);
            }
        });
    }

    /**
     * The priority list with EnsureMfaVerified right before SubstituteBindings
     * (at the end when the app's own list has no SubstituteBindings). By hand,
     * not addToMiddlewarePriorityBefore(): Laravel 11.22 doesn't have it.
     *
     * @param  array<int, string>  $priority
     * @return array<int, string>
     */
    public static function priorityWithGate(array $priority): array
    {
        if (in_array(EnsureMfaVerified::class, $priority, true)) {
            return $priority;
        }

        $at = array_search(SubstituteBindings::class, $priority, true);
        array_splice($priority, $at === false ? count($priority) : $at, 0, [EnsureMfaVerified::class]);

        return $priority;
    }

    private function registerRoutes(): void
    {
        if (config('mfa.enabled') && config('mfa.routes.enabled') && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mfa.php');
        }
    }

    private function registerObservability(): void
    {
        Event::listen(MfaActivity::class, LogMfaActivity::class);
        Event::listen(MfaActivity::class, WriteMfaAuditLog::class);
        Event::listen(MfaActivity::class, RecordMfaMetrics::class);
        Event::listen(array_keys(NotifyAccountOwner::EVENTS), NotifyAccountOwner::class);
        Event::listen(array_keys(ForgetTrustedBrowsers::EVENTS), ForgetTrustedBrowsers::class);
        Event::listen([PasswordReset::class, 'eloquent.updated: '.Mfa::userModel()], [ForgetTrustedBrowsers::class, 'passwordChanged']);
        Event::listen(Logout::class, ForgetMfaStateOnLogout::class);
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\InstallCommand::class,
            Console\DoctorCommand::class,
            Console\StatusCommand::class,
            Console\ResetCommand::class,
            Console\EnrollmentLinkCommand::class,
        ]);

        AboutCommand::add('MFA', fn () => [
            'Enabled' => config('mfa.enabled') ? '<fg=green;options=bold>YES</>' : '<fg=yellow;options=bold>NO</>',
            'Factors' => implode(', ', array_map(fn ($t) => $t->value, $this->app->make(Mfa::class)->enabledTypes())) ?: 'none',
            'SMS driver' => (string) config('mfa.sms.driver'),
            'Delivery' => config('mfa.delivery.queue_connection') || config('mfa.delivery.queue')
                ? 'queued ('.(config('mfa.delivery.queue_connection') ?: config('queue.default')).(config('mfa.delivery.queue') ? ':'.config('mfa.delivery.queue') : '').')'
                : 'sync',
            'Enforcement' => $this->enforcementSummary($this->app->make(Mfa::class)),
            'Audit log' => config('mfa.observability.audit.enabled') ? 'on' : 'off',
        ]);
    }

    private function registerPruning(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('mfa.prune.schedule')) {
                $schedule->command('model:prune', ['--model' => [MfaOtpCode::class, MfaAuditLog::class, MfaTrustedBrowser::class]])
                    ->daily()
                    ->onOneServer()
                    ->withoutOverlapping()
                    ->name('mfa:prune');
            }
        });
    }

    private function enforcementSummary(Mfa $mfa): string
    {
        [$roles, $policy] = $mfa->enforcementRules();

        $who = implode(' + ', array_filter([$roles !== [] ? 'roles: '.implode(', ', $roles) : null, $policy]));

        if ($who === '') {
            return 'opt-in';
        }

        $required = array_map(fn ($t) => $t->value, $mfa->requiredTypes());

        return $who.' (requires '.($required === [] ? 'any factor' : implode(' or ', $required)).')';
    }
}
