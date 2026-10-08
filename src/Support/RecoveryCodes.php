<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Support\Facades\DB;
use StrontiumCorp\LaravelMfa\Contracts\CodeGenerator;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Models\MfaRecoveryCode;

final class RecoveryCodes
{
    private const SCOPE = 'recovery';

    public function __construct(
        private readonly CodeGenerator $generator,
        private readonly CodeHasher $hasher,
        private readonly int $count,
    ) {}

    /**
     * Replace all recovery codes. Plain codes are returned exactly once.
     *
     * @return list<string>
     */
    public function generate(MultiFactorAuthenticatable $user): array
    {
        $codes = [];

        while (count($codes) < $this->count) {
            // Equivalent mutant(s): only the keys are used (for uniqueness).
            $codes[$this->generator->recoveryCode()] = true; // @pest-mutate-ignore: TrueToFalse
        }

        $codes = array_keys($codes);

        DB::transaction(function () use ($user, $codes): void {
            $user->mfaRecoveryCodes()->delete();

            $user->mfaRecoveryCodes()->createMany(array_map(fn (string $code): array => [
                'code_hash' => $this->hasher->hash(self::normalize($code), self::SCOPE),
            ], $codes));
        });

        return $codes;
    }

    /** Atomically mark a code as used. Safe against concurrent double use. */
    public function consume(MultiFactorAuthenticatable $user, string $code): bool
    {
        $normalized = self::normalize($code);

        // Equivalent mutant(s): an empty code matches no stored hash anyway; this only saves a query.
        if ($normalized === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return false;
        }

        $hashes = $this->hasher->candidates($normalized, self::SCOPE);

        /** @var MfaRecoveryCode|null $record */
        $record = $user->mfaRecoveryCodes()->whereIn('code_hash', $hashes)->whereNull('used_at')->first();

        if ($record === null) {
            return false;
        }

        return MfaRecoveryCode::query()
            ->whereKey($record->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    public function remaining(MultiFactorAuthenticatable $user): int
    {
        return $user->mfaRecoveryCodes()->whereNull('used_at')->count();
    }

    public function clear(MultiFactorAuthenticatable $user): void
    {
        $user->mfaRecoveryCodes()->delete();
    }

    public static function normalize(string $code): string
    {
        // Equivalent mutant(s): preg_replace only returns null on a regex error.
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $code)); // @pest-mutate-ignore: RemoveStringCast
    }
}
