<?php

namespace StrontiumCorp\LaravelMfa\Console;

/**
 * The pages, components and theme as each release shipped them
 * (stubs/inertia-react/released.json, written by scripts/released-stubs.py
 * at every release). A published file that still matches one was never
 * changed by the app, so mfa:install may replace it without --force.
 */
final class ReleasedStubs
{
    /** @var array<string, list<string>>|null */
    private ?array $hashes = null;

    public function __construct(private readonly ?string $path = null) {}

    public static function path(): string
    {
        return __DIR__.'/../../stubs/inertia-react/released.json';
    }

    /** Whether $contents is the stub at $stub ("pages/settings.tsx") as some release shipped it. */
    public function contains(string $stub, string $contents): bool
    {
        $this->hashes ??= (array) json_decode((string) @file_get_contents($this->path ?? self::path()), true);

        return in_array(self::hash($contents), (array) ($this->hashes[$stub] ?? []), true);
    }

    /** Line endings don't count: a Windows checkout of an untouched file is still untouched. */
    public static function hash(string $contents): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $contents));
    }
}
