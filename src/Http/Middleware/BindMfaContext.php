<?php

namespace StrontiumCorp\LaravelMfa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attached to MFA's own routes so every event in the flow shares a flow id.
 */
class BindMfaContext
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::bind($request);

        return $next($request);
    }
}
