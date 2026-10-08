<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures\KernelApp\Http\Middleware;

/**
 * A Kernel-style app's proxy middleware (artistly). Lives under its own
 * namespace, which one mfa:doctor test sets as the app namespace, so no
 * other test ever sees it (a global App\ class would leak across tests).
 */
class TrustProxies
{
    protected $proxies = ['10.0.0.0/8'];
}
