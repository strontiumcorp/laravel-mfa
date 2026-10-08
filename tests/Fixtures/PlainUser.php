<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** A user model that does NOT implement MultiFactorAuthenticatable. */
class PlainUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
