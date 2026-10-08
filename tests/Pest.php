<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use StrontiumCorp\LaravelMfa\Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

pest()->extend(TestCase::class)->in('Arch');
