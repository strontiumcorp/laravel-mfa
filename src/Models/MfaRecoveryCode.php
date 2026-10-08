<?php

namespace StrontiumCorp\LaravelMfa\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use StrontiumCorp\LaravelMfa\Mfa;

/**
 * @property int $id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
class MfaRecoveryCode extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    public function getTable(): string
    {
        return config('mfa.tables.recovery_codes');
    }

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Mfa::userModel(), 'user_id');
    }
}
