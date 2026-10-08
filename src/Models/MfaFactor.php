<?php

namespace StrontiumCorp\LaravelMfa\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\Mask;

/**
 * @property int $id
 * @property string $authenticatable_type
 * @property int|string $authenticatable_id
 * @property FactorType $type
 * @property string|null $label
 * @property string|null $secret
 * @property string|null $destination
 * @property int|null $last_totp_timestep
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property-read (Model&MultiFactorAuthenticatable)|null $authenticatable
 */
class MfaFactor extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret', 'destination', 'last_totp_timestep'];

    public function getTable(): string
    {
        return config('mfa.tables.factors');
    }

    protected static function booted(): void
    {
        $forget = static fn (self $factor) => app(Mfa::class)
            ->refreshCachedStateFor($factor->authenticatable_type, $factor->authenticatable_id);

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'type' => FactorType::class,
            'secret' => 'encrypted',
            'destination' => 'encrypted',
            'last_totp_timestep' => 'integer',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<MfaOtpCode, $this> */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(MfaOtpCode::class, 'factor_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** @param Builder<self> $query */
    public function scopeConfirmed(Builder $query): void
    {
        $query->whereNotNull('confirmed_at');
    }

    /**
     * Safe representation for the UI and logs — never includes secrets.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'label' => $this->label,
            'destination' => Mask::destination($this->type, $this->destination),
            'confirmed' => $this->isConfirmed(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
