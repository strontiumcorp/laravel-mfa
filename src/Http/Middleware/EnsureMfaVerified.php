<?php

namespace StrontiumCorp\LaravelMfa\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Events\ChallengeRequired;
use StrontiumCorp\LaravelMfa\Events\EnrollmentRequired;
use StrontiumCorp\LaravelMfa\Events\VerificationExpired;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\ChallengeSteps;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use StrontiumCorp\LaravelMfa\Support\SessionIdentity;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use StrontiumCorp\LaravelMfa\Support\VerificationLifetime;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny-by-default gate for every route in the groups it is attached to.
 *
 * Fast path for the common cases, in order:
 *  - MFA disabled / no session / excluded route  → pass, no work
 *  - guest                                      → pass
 *  - session already verified                   → pass, no queries (one cache
 *    read for revocations; the lifetime window is read from the session; a
 *    session flag holds enforced users who still lack a required factor type)
 *  - user has no factors and is not enforced    → pass, one cached lookup
 *  - unverified, but a browser they trusted     → verified, one query (only
 *    when the browser sends that user's trusted-browser cookie)
 */
class EnsureMfaVerified
{
    /** Always reachable while a challenge is pending. */
    private const CHALLENGE_ROUTES = ['mfa.challenge', 'mfa.challenge.*'];

    /** Reachable by users who must enroll (no factor yet, or no required type). */
    private const ENROLLMENT_ROUTES = ['mfa.settings', 'mfa.factors.*', 'mfa.recovery-codes.*', 'mfa.password.confirm', 'mfa.enrollment-verification.*', 'mfa.session', 'mfa.session.*'];

    public function __construct(
        private readonly Mfa $mfa,
        private readonly TrustedBrowsers $trustedBrowsers,
        private readonly VerificationLifetime $lifetime,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->mfa->enabled() || ! $request->hasSession() || $this->isExcluded($request)) {
            return $next($request);
        }

        // Every logged-in session guard must be satisfied on its own.
        foreach ($this->mfa->sessionIdentities($request) as $identity) {
            $ended = null;
            $stamps = $this->mfa->revocations($identity->id);

            // An administrator's reset (Mfa::reset()) ends every login made before it.
            if ($stamps['logged_out'] > 0 && $this->mfa->loginAt($request->session(), $identity->guard, $identity->id) <= $stamps['logged_out']) {
                RequestContext::bind($request);
                event(new VerificationExpired($identity->user(), null, null, ['cause' => 'revoked', 'on_expiry' => 'logout']));

                return $this->logOut($request, $identity->guard, 'revoked');
            }

            if ($this->mfa->isVerifiedById($request->session(), $identity->guard, $identity->id)) {
                $ended = $this->endedVerification($request, $identity, $stamps['revoked']);

                if ($ended === null) {
                    // Verified, but enforced and still without a required factor type.
                    if ($this->mfa->mustEnrollAfterVerification($request->session(), $identity->guard, $identity->id)
                        && ($denied = $this->requireEnrollment($request, $identity->user()))) {
                        return $denied;
                    }

                    continue;
                }

                if ($ended['on_expiry'] === 'logout') {
                    return $this->logOut($request, $identity->guard, $ended['cause']);
                }
            }

            $user = $identity->user();

            if ($user instanceof MultiFactorAuthenticatable && ($denied = $this->check($request, $user, $identity->guard, $ended['cause'] ?? null))) {
                return $denied;
            }
        }

        return $next($request);
    }

    /**
     * For a verified identity: null while its verification still holds
     * (recording activity), else why it ended, after ending it. Revoked
     * first (Mfa::revokeVerifications(), for this user or for the admin
     * whose impersonation grant it rides on), then the lifetime window
     * (mfa.lifetime): past its end plus grace, idle too long, or in the
     * grace period and this request starts a new task (a page visit)
     * instead of finishing one.
     *
     * @return array{cause: string, on_expiry: string}|null
     */
    private function endedVerification(Request $request, SessionIdentity $identity, int $revokedAt): ?array
    {
        $session = $request->session();
        $verifiedAt = (int) $this->mfa->verifiedAt($session, $identity->guard, $identity->id);
        $entry = $this->lifetime->entry($session, $identity->guard, $identity->id);

        if ($entry === null) {
            // Verified before lifetimes existed, or by a test helper: the
            // profile is decided once, counted from that verification.
            $user = $identity->user();
            $entry = $user instanceof MultiFactorAuthenticatable
                ? $this->lifetime->start($session, $identity->guard, $user, $verifiedAt)
                : null;
        }

        if (($revokedAt > 0 && $verifiedAt <= $revokedAt) || $this->impersonatorRevoked($session, $identity)) {
            $cause = 'revoked';
        } elseif ($entry === null) {
            return null;
        } else {
            $cause = $this->lifetime->status($session, $identity->guard, $identity->id, $entry);

            if ($cause === 'grace' && ! $this->startsNewTask($request)) {
                $cause = null;
            }
        }

        if ($cause === null) {
            if ($entry !== null && $entry['idle'] !== null && $this->countsAsActivity($request)) {
                $this->lifetime->touch($session, $identity->guard, $identity->id);
                $this->touchImpersonator($session, $identity);
            }

            return null;
        }

        $cause = $cause === 'grace' ? 'absolute' : $cause;
        $onExpiry = $entry['on_expiry'] ?? 'challenge';

        $session->forget([
            $this->mfa->sessionKey($identity->guard, $identity->id),
            Mfa::ENROLL_PREFIX.'.'.$identity->guard.'.'.$identity->id,
            TrustedBrowsers::SESSION_KEY.'.'.$identity->guard.'.'.$identity->id,
            // A revoked session (perhaps not the owner's) keeps no head start:
            // no confirmed password to add a factor with, no setup in progress.
            ...($cause === 'revoked' ? [Mfa::PASSWORD_CONFIRMED_AT, 'mfa.pending', ChallengeSteps::SESSION_KEY.'.'.$identity->guard.'.'.$identity->id] : []),
        ]);
        $this->lifetime->forget($session, $identity->guard, $identity->id);
        $session->forget(Mfa::IMPERSONATOR_PREFIX.'.'.$identity->guard.'.'.$identity->id);

        RequestContext::bind($request);
        event(new VerificationExpired($identity->user(), null, null, [
            'cause' => $cause,
            'profile' => $entry['profile'] ?? null,
            'verified_for' => max(0, now()->getTimestamp() - $verifiedAt),
            'on_expiry' => $onExpiry,
        ]));

        return ['cause' => $cause, 'on_expiry' => $onExpiry];
    }

    /**
     * An impersonation (Mfa::grantForImpersonation()) ends when the admin's
     * own verifications are revoked, or their logins ended, after the grant.
     * One more cache read, only while impersonating.
     */
    private function impersonatorRevoked(Session $session, SessionIdentity $identity): bool
    {
        $grant = $session->get(Mfa::IMPERSONATOR_PREFIX.'.'.$identity->guard.'.'.$identity->id);

        if (! is_array($grant) || ! isset($grant['id'], $grant['since'])) {
            return false;
        }

        $stamps = $this->mfa->revocations($grant['id']);

        return max($stamps['revoked'], $stamps['logged_out']) >= (int) $grant['since'];
    }

    /**
     * The admin behind an impersonation is active too: switching back must
     * not find their own idle timeout run out.
     */
    private function touchImpersonator(Session $session, SessionIdentity $identity): void
    {
        $grant = $session->get(Mfa::IMPERSONATOR_PREFIX.'.'.$identity->guard.'.'.$identity->id);

        if (! is_array($grant) || ! isset($grant['id'], $grant['guard'])) {
            return;
        }

        $entry = $this->lifetime->entry($session, (string) $grant['guard'], $grant['id']);

        if ($entry !== null && $entry['idle'] !== null) {
            $this->lifetime->touch($session, (string) $grant['guard'], $grant['id']);
        }
    }

    /**
     * Whether this request begins something new rather than finishing what
     * the user was doing: a page visit (a top-level GET, or an Inertia visit
     * that isn't a partial reload or prefetch). In the grace period only
     * these are challenged; form submits and background requests pass. Routes
     * in lifetime.no_grace always count, so sensitive actions get no grace.
     */
    private function startsNewTask(Request $request): bool
    {
        // Equivalent mutant(s): the config value is always a list.
        if ($this->matches($request, (array) config('mfa.lifetime.no_grace'))) { // @pest-mutate-ignore: RemoveArrayCast
            return true;
        }

        return in_array($request->getMethod(), ['GET', 'HEAD'], true) && $this->isVisit($request);
    }

    /**
     * Whether this request shows the user is there, for the idle timeout:
     * a page or Inertia visit, or any non-GET request. Not background GETs
     * (fetch, Inertia partial reloads and prefetches, which polling would
     * send forever), MFA's own deadline lookup, or lifetime.idle_ignore.
     */
    private function countsAsActivity(Request $request): bool
    {
        // Equivalent mutant(s): the config value is always a list.
        if ($this->routeIs($request, ['mfa.session']) || $this->matches($request, (array) config('mfa.lifetime.idle_ignore'))) { // @pest-mutate-ignore: RemoveArrayCast
            return false;
        }

        return ! in_array($request->getMethod(), ['GET', 'HEAD'], true) || $this->isVisit($request);
    }

    /**
     * A page visit: a top-level navigation, or an Inertia visit that isn't a
     * partial reload, a prefetch, or a reload of the page the user is on
     * (router.reload() and usePoll() without `only`: the Referer is that
     * page), so polling neither keeps a session alive nor ends it in grace.
     */
    private function isVisit(Request $request): bool
    {
        if (! $request->header('X-Inertia')) {
            return $this->isPageNavigation($request);
        }

        $referer = (string) $request->headers->get('referer');
        // Against the full path (an app served under a subdirectory has it in its base URL).
        $samePage = $referer !== '' && '/'.ltrim((string) parse_url($referer, PHP_URL_PATH), '/') === '/'.ltrim($request->getBaseUrl().$request->getPathInfo(), '/');

        return ! $request->header('X-Inertia-Partial-Data') && ! $this->isPrefetch($request) && ! $samePage;
    }

    /**
     * on_expiry "logout": the verification's end ends the login too, like
     * the app's own logout (remember-me forgotten, session invalidated).
     */
    private function logOut(Request $request, string $guard, string $cause): Response
    {
        $auth = Auth::guard($guard);
        if ($auth instanceof StatefulGuard) {
            $auth->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $url = Route::has('login') ? route('login') : url((string) config('mfa.routes.home'));

        if ($this->wantsJson($request)) {
            return response()->json(['message' => 'Your session has ended. Please sign in again.', 'error' => 'mfa_session_ended', 'reason' => $cause, 'redirect' => $url], 401);
        }

        return redirect()->to($url, in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 302 : 303);
    }

    /** A denial response if this unverified user may not proceed, else null. */
    private function check(Request $request, MultiFactorAuthenticatable $user, string $guard, ?string $expired = null): ?Response
    {
        if ($this->mfa->hasConfirmedFactors($user)) {
            // A browser the user trusted after an earlier challenge (only
            // looked up when it sends that user's cookie).
            if ($this->trustedBrowsers->attempt($request, $user, $guard)) {
                return $this->mfa->mustEnrollAfterVerification($request->session(), $guard, $user->getAuthIdentifier())
                    ? $this->requireEnrollment($request, $user)
                    : null;
            }

            if ($this->routeIs($request, self::CHALLENGE_ROUTES)) {
                return null;
            }

            RequestContext::bind($request);
            event(new ChallengeRequired($user, null, null, ['path' => $request->getPathInfo()]));

            return $this->deny($request, 'mfa_required', 'Multi-factor authentication required.', 'mfa.challenge', $expired);
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

    /** @param string|null $reason why an earlier verification ended ("absolute" | "idle" | "revoked") */
    private function deny(Request $request, string $error, string $message, string $route, ?string $reason = null): Response
    {
        $url = route($route);

        if ($this->wantsJson($request)) {
            return response()->json(array_filter(['message' => $message, 'error' => $error, 'reason' => $reason, 'redirect' => $url], fn ($v) => $v !== null), 403);
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

        if ($this->isPrefetch($request)) {
            return false;
        }

        if ($request->headers->has('Sec-Fetch-Mode')) {
            return $request->headers->get('Sec-Fetch-Mode') === 'navigate'
                && in_array($request->headers->get('Sec-Fetch-Dest', 'document'), ['document', ''], true);
        }

        return in_array('text/html', $request->getAcceptableContentTypes(), true);
    }

    private function isPrefetch(Request $request): bool
    {
        $purpose = $request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? '';

        return str_contains($purpose, 'prefetch');
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
