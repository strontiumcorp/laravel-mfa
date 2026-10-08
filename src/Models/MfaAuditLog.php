<?php

namespace StrontiumCorp\LaravelMfa\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use StrontiumCorp\LaravelMfa\Mfa;

/**
 * @property int $id
 * @property string $event
 * @property string|null $factor_type
 * @property string|null $reason
 * @property string|null $flow_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
class MfaAuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('mfa.tables.audit_logs');
    }

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Mfa::userModel(), 'user_id');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at', '<', now()->subDays((int) config('mfa.observability.audit.retention_days'))
        );
    }
}
