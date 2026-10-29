<?php

use Illuminate\Support\Facades\Schema;

/*
 * Every migration on every server the apps run: MySQL 8 names
 * information_schema columns in upper case, PostgreSQL has no `extra` or
 * `database()`. artistly's CI and a MySQL 8 dev setup failed on that.
 */

it('rolls every migration back and runs them again', function () {
    $tables = array_values(config('mfa.tables'));
    $path = ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true];

    $this->artisan('migrate:rollback', $path + ['--step' => 100])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} survived the rollback");
    }

    $this->artisan('migrate', $path)->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} wasn't created again");
    }
});
