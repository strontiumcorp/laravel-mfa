<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mirrors clone-voice / podcast-flow: swaps the user per request with
 * Auth::setUser() while the session keeps the admin's identity.
 */
class HandleImpersonation
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($id = $request->session()->get('impersonated_id')) {
            Auth::setUser(User::findOrFail($id));
        }

        return $next($request);
    }
}
