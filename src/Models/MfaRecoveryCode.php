<?php

namespace StrontiumCorp\LaravelMfa\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

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

    /** @return MorphTo<Model, $this> */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
