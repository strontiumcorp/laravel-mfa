<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Keyed HMAC for OTPs and recovery codes.
 *
 * HMAC (not bcrypt) is the right tool here: the inputs are short-lived or
 * high-entropy, attempts are rate limited, and a deterministic hash lets us
 * look recovery codes up by index instead of scanning rows. The key is
 * derived from APP_KEY, and APP_PREVIOUS_KEYS keep working after rotation.
 */
final class CodeHasher
{
    /** @var non-empty-list<string> */
    private array $keys;

    /** @param list<string> $rawKeys current key first */
    public function __construct(array $rawKeys)
    {
        $rawKeys = array_values(array_filter($rawKeys));

        if ($rawKeys === []) {
            throw new RuntimeException('laravel-mfa requires APP_KEY to be set.');
        }

        $this->keys = array_map(
            // Equivalent mutant(s): raw vs hex derived key — either is a consistent secret key.
            static fn (string $key): string => hash_hmac('sha256', 'strontiumcorp/laravel-mfa', self::decode($key), true), // @pest-mutate-ignore: TrueToFalse
            $rawKeys,
        );
    }

    public static function fromConfig(Repository $config): self
    {
        $previous = $config->get('app.previous_keys', []);

        // Equivalent mutant(s): a missing key is filtered out and fails the same way.
        return new self([(string) $config->get('app.key'), ...(is_array($previous) ? $previous : [])]); // @pest-mutate-ignore: RemoveStringCast
    }

    public function hash(string $code, string $scope = ''): string
    {
        return $this->hashWith($this->keys[0], $code, $scope);
    }

    /** @return non-empty-list<string> hashes under the current key first, then every previous key */
    public function candidates(string $code, string $scope = ''): array
    {
        return array_map(fn (string $key): string => $this->hashWith($key, $code, $scope), $this->keys);
    }

    public function matches(string $code, string $hash, string $scope = ''): bool
    {
        $matched = false;

        foreach ($this->candidates($code, $scope) as $candidate) {
            $matched = hash_equals($candidate, $hash) || $matched;
        }

        return $matched;
    }

    private function hashWith(string $key, string $code, string $scope): string
    {
        // Equivalent mutant(s): any fixed ordering is equally unambiguous; removing the separator is tested.
        return hash_hmac('sha256', $scope."\0".$code, $key); // @pest-mutate-ignore: ConcatSwitchSides
    }

    private static function decode(string $key): string
    {
        // Equivalent mutant(s): APP_KEY is valid base64; strict mode only differs for invalid input.
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key; // @pest-mutate-ignore: RemoveStringCast,TrueToFalse
    }
}
