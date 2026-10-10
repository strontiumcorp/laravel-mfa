<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/** A cache store that reads but can't write or forget (a failover in progress). */
class WriteFailingCacheStore extends ArrayStore
{
    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException('cache read-only');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('cache read-only');
    }
}
