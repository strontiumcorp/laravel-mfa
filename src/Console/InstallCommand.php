<?php

namespace StrontiumCorp\LaravelMfa\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    /** Where the components go, relative to the JS root; always lowercase. */
    public const COMPONENTS_DIR = 'components/vendor/laravel-mfa';

    protected $signature = 'mfa:install
        {--no-ui : Do not publish the React/Inertia pages and components}
        {--force : Overwrite existing files}
        {--js-path= : Frontend source directory (default: resources/js)}';

    protected $description = 'Publish the MFA config, UI pages and components, and print the remaining integration steps';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'mfa-config', '--force' => (bool) $this->option('force')]);

        $base = rtrim((string) ($this->option('js-path') ?: resource_path('js')), '/');
        $pagesDir = collect(['Pages', 'pages'])->first(fn ($dir) => $files->isDirectory("{$base}/{$dir}")) ?? 'pages';

        if (! $this->option('no-ui')) {
            $this->publishUi($files, $base, $pagesDir);
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  1. Add to your User model:');
        $this->line('       <fg=gray>use</> StrontiumCorp\LaravelMfa\Concerns\HasMultiFactorAuthentication;');
        $this->line('       <fg=gray>use</> StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;');
        $this->line('       <fg=gray>class</> User <fg=gray>extends</> Authenticatable <fg=gray>implements</> MultiFactorAuthenticatable');
        $this->line('       { <fg=gray>use</> HasMultiFactorAuthentication; ... }');
        $this->line('  2. php artisan migrate');
        $this->line('  3. Enable factors / SMS credentials in config/mfa.php or .env');
        $this->line('     The settings page asks for the password before factor changes (no confirm page needed).');
        $this->line('     Social-login users without a password: routes.password_confirmation_policy');
        $this->line('     (a Contracts\\PasswordConfirmationPolicy class), or routes.password_confirmation => false');
        $this->line('  4. Share the MFA context in HandleInertiaRequests::share():');
        $this->line('       \'mfa\' => fn () => \\StrontiumCorp\\LaravelMfa\\Facades\\Mfa::context($request),');
        $this->line('     add the two-factor card to your account settings page:');
        $this->line('       <fg=gray>import</> MfaSettingsCard <fg=gray>from</> \'@/'.self::COMPONENTS_DIR.'/settings-card\';');
        $this->line("       <fg=gray>import</> { mfaSettingsCardProps, useMfa } <fg=gray>from</> '@/{$pagesDir}/mfa/mfa-context';");
        $this->line('       <MfaSettingsCard {...mfaSettingsCardProps(useMfa())} renderLink={(link) => <Link {...link} />} />');
        $this->line('     and show the API-key notice next to API keys:');
        $this->line('       <fg=gray>import</> MfaApiKeyNotice <fg=gray>from</> \'@/'.self::COMPONENTS_DIR.'/api-key-notice\';');
        $this->line("       <fg=gray>import</> { mfaApiKeyNoticeProps, useMfa } <fg=gray>from</> '@/{$pagesDir}/mfa/mfa-context';");
        $this->line('       <MfaApiKeyNotice {...mfaApiKeyNoticeProps(useMfa())} />');
        $this->line('     and mount the "turn on two-factor" nudge in your global layout:');
        $this->line('       <fg=gray>import</> MfaEnableNudge <fg=gray>from</> \'@/'.self::COMPONENTS_DIR.'/enable-nudge\';');
        $this->line("       <fg=gray>import</> { useMfaNudge } <fg=gray>from</> '@/{$pagesDir}/mfa/mfa-context';");
        $this->line('       <MfaEnableNudge {...useMfaNudge()} />');
        $this->line('  5. php artisan mfa:doctor   <fg=gray># verifies the integration</>');

        return self::SUCCESS;
    }

    /**
     * Pages (thin Inertia wrappers, plus the useMfa() hook) go to the app's
     * pages directory. The components are plain React and always go to
     * components/vendor/laravel-mfa/, whatever casing the app's own
     * component directories use; the pages import them through "@/".
     */
    private function publishUi(Filesystem $files, string $base, string $pagesDir): void
    {
        $stubs = __DIR__.'/../../stubs/inertia-react';
        $this->publishDirectory($files, "{$stubs}/pages", "{$base}/{$pagesDir}/mfa");
        $this->publishDirectory($files, "{$stubs}/components", "{$base}/".self::COMPONENTS_DIR);

        // v0.1 published two files to {Components|components}/mfa/.
        foreach (['Components', 'components'] as $dir) {
            $old = "{$base}/{$dir}/mfa";

            if ($files->exists("{$old}/mfa-context.ts") || $files->exists("{$old}/api-key-notice.tsx")) {
                $this->components->warn("Found files from an earlier version in {$old}/. Import from @/".self::COMPONENTS_DIR."/ and @/{$pagesDir}/mfa/mfa-context instead, then delete them.");

                // On a case-insensitive filesystem both spellings are the same directory.
                break;
            }
        }
    }

    private function publishDirectory(Filesystem $files, string $from, string $target): void
    {
        $files->ensureDirectoryExists($target);

        foreach ($files->files($from) as $stub) {
            $destination = $target.'/'.$stub->getFilename();

            if ($files->exists($destination) && ! $this->option('force')) {
                $this->components->warn("Skipped (exists): {$target}/{$stub->getFilename()}");

                continue;
            }

            $files->copy($stub->getPathname(), $destination);
            $this->components->task("{$target}/{$stub->getFilename()}");
        }
    }
}
