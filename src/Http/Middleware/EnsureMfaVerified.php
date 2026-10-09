<?php

namespace StrontiumCorp\LaravelMfa\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Events\ChallengeRequired;
use StrontiumCorp\LaravelMfa\Events\EnrollmentRequired;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny-by-default gate for every route in the groups it is attached to.
 *
 * Fast path for the common cases, in order:
 *  - MFA disabled / no session / excluded route  → pass, no work
 *  - guest                                      → pass
 *  - session already verified                   → pass, no queries (a session
 *    flag holds enforced users who still lack a required factor type)
 *  - user has no factors and is not enforced    → pass, one cached lookup
 */
class EnsureMfaVerified
{
    /** Always reachable while a challenge is pending. */
    private const CHALLENGE_ROUTES = ['mfa.challenge', 'mfa.challenge.*'];

    /** Reachable by users who must enroll (no factor yet, or no required type). */
    private const ENROLLMENT_ROUTES = ['mfa.settings', 'mfa.factors.*', 'mfa.recovery-codes.*', 'mfa.password.confirm'];

    public function __construct(private readonly Mfa $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->mfa->enabled() || ! $request->hasSession() || $this->isExcluded($request)) {
            return $next($request);
        }

        // Every logged-in session guard must be satisfied on its own.
        foreach ($this->mfa->sessionIdentities($request) as $identity) {
            if ($this->mfa->isVerifiedById($request->session(), $identity->guard, $identity->id)) {
                // Verified, but enforced and still without a required factor type.
                if ($this->mfa->mustEnrollAfterVerification($request->session(), $identity->guard, $identity->id)
                    && ($denied = $this->requireEnrollment($request, $identity->user()))) {
                    return $denied;
                }

                continue;
            }

            $user = $identity->user();

            if ($user instanceof MultiFactorAuthenticatable && ($denied = $this->check($request, $user))) {
                return $denied;
            }
        }

        return $next($request);
    }

    /** A denial response if this unverified user may not proceed, else null. */
    private function check(Request $request, MultiFactorAuthenticatable $user): ?Response
    {
        if ($this->mfa->hasConfirmedFactors($user)) {
            if ($this->routeIs($request, self::CHALLENGE_ROUTES)) {
                return null;
            }

            RequestContext::bind($request);
            event(new ChallengeRequired($user, null, null, ['path' => $request->getPathInfo()]));

            return $this->deny($request, 'mfa_required', 'Multi-factor authentication required.', 'mfa.challenge');
        }

        if ($this->mfa->mustEnroll($user)) {
            return $this->requireEnrollment($request, $user);
        }

        return null;
    }

    /** Only the enrollment pages (and allow_while_enrolling) until the user enrolls. */
    private function requireEnrollment(Request $request, ?Authenticatable $user): ?Response
    {
        // Equivalent mutant(s): the config value is always a list.
        if ($this->routeIs($request, self::ENROLLMENT_ROUTES) || $this->matches($request, (array) config('mfa.middleware.allow_while_enrolling'))) { // @pest-mutate-ignore: RemoveArrayCast
            return null;
        }

        if ($user instanceof MultiFactorAuthenticatable) {
            RequestContext::bind($request);
            event(new EnrollmentRequired($user, null, null, ['path' => $request->getPathInfo()]));
        }

        return $this->deny($request, 'mfa_enrollment_required', 'You must set up multi-factor authentication.', 'mfa.settings');
    }

    private function deny(Request $request, string $error, string $message, string $route): Response
    {
        $url = route($route);

        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message, 'error' => $error, 'redirect' => $url], 403);
        }

        if ($this->isPageNavigation($request)) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        // 303 makes the browser follow a blocked PUT/PATCH/DELETE/POST (e.g. a
        // stale tab's router.put()) with a GET. This gate runs before
        // HandleInertiaRequests, and inertia-laravel 2.x has no global
        // middleware that would turn the 302 into a 303.
        return redirect()->to($url, in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 302 : 303);
    }

    /**
     * JSON for API calls and for a script's fetch()/XHR, which Fetch Metadata
     * marks as not a navigation (a background poll must not get, and parse,
     * the challenge page). Never for Inertia visits: they follow redirects.
     */
    private function wantsJson(Request $request): bool
    {
        if ($request->header('X-Inertia')) {
            return false;
        }

        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $request->expectsJson() || ($mode !== null && $mode !== 'navigate');
    }

    /**
     * Whether the user is opening this page in the browser, so it is where
     * they should land after verifying (url.intended). With Fetch Metadata:
     * a top-level navigation that isn't a prefetch or prerender. Without it
     * (older browsers): a GET that asks for HTML by name, so a fetch(),
     * which accepts anything, never overwrites the page the user was going to.
     */
    private function isPageNavigation(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->header('X-Inertia')) {
            return false;
        }

        $purpose = $request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? '';

        if (str_contains($purpose, 'prefetch')) {
            return false;
        }

        if ($request->headers->has('Sec-Fetch-Mode')) {
            return $request->headers->get('Sec-Fetch-Mode') === 'navigate'
                && in_array($request->headers->get('Sec-Fetch-Dest', 'document'), ['document', ''], true);
        }

        return in_array('text/html', $request->getAcceptableContentTypes(), true);
    }

    private function isExcluded(Request $request): bool
    {
        // The challenge page's "Sign out" posts to routes.logout_route, so it
        // must always be reachable, wherever the app's logout lives.
        $logout = config('mfa.routes.logout_route');

        if (is_string($logout) && $logout !== '' && $request->route() !== null && $request->routeIs($logout)) {
            return true;
        }

        // Equivalent mutant(s): the config value is always a list.
        return $this->matches($request, (array) config('mfa.middleware.except')); // @pest-mutate-ignore: RemoveArrayCast
    }

    /** @param array<int, string> $patterns route names or paths */
    private function matches(Request $request, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($request->routeIs($pattern) || $request->is(ltrim($pattern, '/'))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private function routeIs(Request $request, array $patterns): bool
    {
        return $request->route() !== null && $request->routeIs(...$patterns);
    }
}
