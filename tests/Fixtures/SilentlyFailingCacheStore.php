<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;

/** A cache store whose writes fail without throwing (as Memcached's set() can). */
class SilentlyFailingCacheStore extends ArrayStore
{
    public static bool $failing = false;

    public function put($key, $value, $seconds): bool
    {
        return self::$failing ? false : parent::put($key, $value, $seconds);
    }

    public function forget($key): bool
    {
        return self::$failing ? false : parent::forget($key);
    }
}
