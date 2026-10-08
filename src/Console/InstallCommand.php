<?php

namespace StrontiumCorp\LaravelMfa\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    protected $signature = 'mfa:install
        {--no-ui : Do not publish the React/Inertia pages}
        {--force : Overwrite existing files}
        {--js-path= : Frontend source directory (default: resources/js)}';

    protected $description = 'Publish the MFA config and UI pages, and print the remaining integration steps';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'mfa-config', '--force' => (bool) $this->option('force')]);

        if (! $this->option('no-ui')) {
            $this->publishUi($files);
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
        $this->line('  4. Link to route(\'mfa.settings\') from your account settings page');
        $this->line('     Share the MFA context in HandleInertiaRequests::share():');
        $this->line('       \'mfa\' => fn () => \\StrontiumCorp\\LaravelMfa\\Facades\\Mfa::context($request),');
        $this->line('     and show <MfaApiKeyNotice /> (components/mfa/api-key-notice) next to API keys');
        $this->line('  5. php artisan mfa:doctor   <fg=gray># verifies the integration</>');

        return self::SUCCESS;
    }

    private function publishUi(Filesystem $files): void
    {
        $base = rtrim((string) ($this->option('js-path') ?: resource_path('js')), '/');
        $pagesDir = collect(['Pages', 'pages'])->first(fn ($dir) => $files->isDirectory("{$base}/{$dir}")) ?? 'pages';
        // Match the pages directory's casing when both exist (e.g. Pages/ + Components/).
        $candidates = $pagesDir === 'Pages' ? ['Components', 'components'] : ['components', 'Components'];
        $componentsDir = collect($candidates)->first(fn ($dir) => $files->isDirectory("{$base}/{$dir}")) ?? $candidates[0];

        $stubs = __DIR__.'/../../stubs/inertia-react';
        $this->publishDirectory($files, $stubs, "{$base}/{$pagesDir}/mfa");
        $this->publishDirectory($files, "{$stubs}/components", "{$base}/{$componentsDir}/mfa");
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
