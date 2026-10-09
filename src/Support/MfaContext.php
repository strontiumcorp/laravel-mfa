<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Everything the frontend needs to know about MFA, as one value. Share it
 * with every Inertia page under a single key:
 *
 *   // HandleInertiaRequests::share()
 *   'mfa' => fn () => Mfa::context($request),
 *
 * Mirrored by the MfaContext type in the published {Pages|pages}/mfa/mfa-context.ts;
 * the shape is pinned by tests/Feature/MfaContextTest.php. Change both together.
 *
 * @implements Arrayable<string, mixed>
 */
final class MfaContext implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $factors  enabled factor types
     * @param  array{hasMfa: bool, verified: bool, mustEnroll: bool}|null  $user  null for guests
     * @param  array{settings: string|null, challenge: string|null}  $urls  null when MFA routes are off
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $factors,
        public readonly bool $passwordConfirmation,
        public readonly ?array $user,
        public readonly array $urls,
    ) {}

    /**
     * @return array{enabled: bool, factors: list<string>, passwordConfirmation: bool, user: array{hasMfa: bool, verified: bool, mustEnroll: bool}|null, urls: array{settings: string|null, challenge: string|null}}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'factors' => $this->factors,
            'passwordConfirmation' => $this->passwordConfirmation,
            'user' => $this->user,
            'urls' => $this->urls,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
