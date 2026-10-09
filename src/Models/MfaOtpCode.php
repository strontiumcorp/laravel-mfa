<?php

namespace StrontiumCorp\LaravelMfa\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $factor_id
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
class MfaOtpCode extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    public function getTable(): string
    {
        return config('mfa.tables.otp_codes');
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Used by a successful verification. verify() sets consumed_at in three
     * cases, told apart without another column: a burned code has
     * max_attempts wrong guesses, and an expired one was consumed after
     * expires_at. A code consumed in the second it expired counts as
     * verified (timestamps are whole seconds), which only makes the next
     * send wait, never sooner. A code superseded by a newer one
     * (OtpStore::issue()) gets an expiry a second before it was consumed,
     * so it always reads as expired.
     */
    public function wasVerified(int $maxAttempts): bool
    {
        return $this->consumed_at !== null
            && $this->attempts < $maxAttempts
            && $this->consumed_at->lte($this->expires_at);
    }

    /** @return BelongsTo<MfaFactor, $this> */
    public function factor(): BelongsTo
    {
        return $this->belongsTo(MfaFactor::class, 'factor_id');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }
}
