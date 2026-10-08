<?php

namespace App\Http\Middleware;

/**
 * A Kernel-style app's proxy middleware (artistly), loaded only by the
 * mfa:doctor test that needs it.
 */
class TrustProxies
{
    protected $proxies = ['10.0.0.0/8'];
}
