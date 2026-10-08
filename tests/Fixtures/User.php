<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use StrontiumCorp\LaravelMfa\Concerns\HasMultiFactorAuthentication;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

class User extends Authenticatable implements MultiFactorAuthenticatable
{
    use HasMultiFactorAuthentication;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean', 'password' => 'hashed'];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }
}
