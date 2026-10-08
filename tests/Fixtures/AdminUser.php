<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

/**
 * Same table as User, different morph type: proves budgets/caches are keyed
 * by model type + id, not id alone.
 */
class AdminUser extends User
{
    protected $table = 'users';

    public function getMorphClass(): string
    {
        return 'admin';
    }
}
