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
        {--ui-only : Publish only the pages, components and theme (to update them; the config is left alone)}
        {--force : Overwrite existing files, also ones the app changed}
        {--js-path= : Frontend source directory (default: resources/js)}
        {--css-path= : Stylesheet directory, for the theme (default: resources/css)}
        {--tailwind= : The app\'s Tailwind major, 3 or 4, for the theme (default: from package.json, else 4)}';

    protected $description = 'Publish the MFA config, UI pages and components, and print the remaining integration steps';

    /** Published files the app had changed, so left as they are (no --force). */
    private int $kept = 0;

    private ReleasedStubs $released;

    public function handle(Filesystem $files, ReleasedStubs $released): int
    {
        // The command instance is reused when called more than once in a process.
        $this->kept = 0;
        $this->released = $released;

        if ($this->option('ui-only') && $this->option('no-ui')) {
            $this->components->error("--ui-only and --no-ui can't be used together.");

            return self::FAILURE;
        }

        $tailwind = $this->option('tailwind');
        if ($tailwind !== null && ! in_array($tailwind, ['3', '4'], true)) {
            $this->components->error('--tailwind must be 3 or 4.');

            return self::FAILURE;
        }
        $tailwind = (int) ($tailwind ?? self::tailwindMajor(is_file(base_path('package.json')) ? (string) file_get_contents(base_path('package.json')) : null) ?? 4);

        if (! $this->option('ui-only')) {
            $this->call('vendor:publish', ['--tag' => 'mfa-config', '--force' => (bool) $this->option('force')]);
        }

        $base = rtrim((string) ($this->option('js-path') ?: resource_path('js')), '/');
        $pagesDir = collect(['Pages', 'pages'])->first(fn ($dir) => $files->isDirectory("{$base}/{$dir}")) ?? 'pages';

        if (! $this->option('no-ui')) {
            $this->publishUi($files, $base, $pagesDir);
            $this->publishTheme($files, rtrim((string) ($this->option('css-path') ?: resource_path('css')), '/'), $tailwind);
        }

        if ($this->kept > 0) {
            $this->newLine();
            $this->components->warn($this->kept === 1
                ? '1 file changed in the app was kept. Merge the new version by hand, or replace it with --force.'
                : "{$this->kept} files changed in the app were kept. Merge the new versions by hand, or replace them with --force.");
        }

        if ($this->option('ui-only')) {
            return self::SUCCESS;
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
        $this->line('     and, next to it, the idle warning for users with an idle timeout:');
        $this->line('       <fg=gray>import</> MfaIdleWarning <fg=gray>from</> \'@/'.self::COMPONENTS_DIR.'/idle-warning\';');
        $this->line("       <fg=gray>import</> { useMfaIdleWarning } <fg=gray>from</> '@/{$pagesDir}/mfa/mfa-context';");
        $this->line('       <MfaIdleWarning {...useMfaIdleWarning()} />');
        $this->line('  5. Give the MFA screens your primary colour: import the theme from resources/css/app.css'.($tailwind < 4 ? ', before the @tailwind directives:' : ':'));
        $this->line("       @import './vendor/laravel-mfa/theme.css';");
        $this->line('     It follows your shadcn/ui --primary and --primary-foreground; edit it to differ.');
        $this->line('  6. php artisan mfa:doctor   <fg=gray># verifies the integration</>');

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
        $this->publishDirectory($files, "{$stubs}/pages", "{$base}/{$pagesDir}/mfa", 'pages');
        $this->publishDirectory($files, "{$stubs}/components", "{$base}/".self::COMPONENTS_DIR, 'components');

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

    /**
     * The theme for the app's Tailwind: shadcn/ui keeps its colours as HSL
     * channels on Tailwind 3 and as full colours on 4.
     */
    private function publishTheme(Filesystem $files, string $css, int $tailwind): void
    {
        $name = $tailwind < 4 ? 'theme.tailwind-3.css' : 'theme.css';

        $this->publishFile($files, __DIR__."/../../stubs/inertia-react/css/{$name}", "{$css}/vendor/laravel-mfa/theme.css", "css/{$name}");
    }

    /** The Tailwind major in package.json's dependencies (null if none, or not a version). */
    public static function tailwindMajor(?string $packageJson): ?int
    {
        $package = json_decode((string) $packageJson, true);
        $version = is_array($package) ? ($package['devDependencies']['tailwindcss'] ?? $package['dependencies']['tailwindcss'] ?? null) : null;

        return is_string($version) && preg_match('/\d+/', $version, $match) ? (int) $match[0] : null;
    }

    private function publishDirectory(Filesystem $files, string $from, string $target, string $kind): void
    {
        $files->ensureDirectoryExists($target);

        foreach ($files->files($from) as $stub) {
            $this->publishFile($files, $stub->getPathname(), $target.'/'.$stub->getFilename(), "{$kind}/{$stub->getFilename()}");
        }
    }

    /**
     * Publish one file. An existing one is replaced when --force says so, or
     * when it is still the file some release published ($stub, e.g.
     * "pages/settings.tsx", in ReleasedStubs): the app never changed it.
     * One the app changed is kept and counted.
     */
    private function publishFile(Filesystem $files, string $from, string $to, string $stub): void
    {
        if ($files->exists($to) && ! $this->option('force')) {
            $current = $files->get($to);

            if (ReleasedStubs::hash($current) === ReleasedStubs::hash($files->get($from))) {
                $this->components->twoColumnDetail($to, '<fg=gray>UP TO DATE</>');

                return;
            }

            if (! $this->released->contains($stub, $current)) {
                $this->components->warn("Kept (changed in the app; --force replaces it): {$to}");
                $this->kept++;

                return;
            }
        }

        $files->ensureDirectoryExists(dirname($to));
        $files->copy($from, $to);
        $this->components->task($to);
    }
}
