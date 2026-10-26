<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use StrontiumCorp\LaravelMfa\Tests\DatabaseServerTestCase;
use StrontiumCorp\LaravelMfa\Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

pest()->extend(TestCase::class)->in('Arch');

// Against a real MySQL/MariaDB server; skipped unless MFA_TEST_DB_HOST is set (make test-db).
pest()->extend(DatabaseServerTestCase::class)->use(RefreshDatabase::class)->in('Database');
