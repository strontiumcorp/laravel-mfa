<?php

namespace StrontiumCorp\LaravelMfa\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use StrontiumCorp\LaravelMfa\Mfa;

/**
 * A browser a user chose to trust after a challenge (trusted_browsers).
 *
 * @property int $id
 * @property int|string $user_id
 * @property string $guard
 * @property string $token_hash
 * @property string $password_hash
 * @property string|null $label
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class MfaTrustedBrowser extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'password_hash'];

    public function getTable(): string
    {
        return config('mfa.tables.trusted_browsers');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Mfa::userModel(), 'user_id');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
