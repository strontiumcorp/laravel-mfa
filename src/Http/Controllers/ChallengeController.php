<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\ChallengeStepPassed;
use StrontiumCorp\LaravelMfa\Factors\OtpFactor;
use StrontiumCorp\LaravelMfa\Factors\TotpFactor;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\ChallengeService;
use StrontiumCorp\LaravelMfa\Support\ChallengeState;
use StrontiumCorp\LaravelMfa\Support\ChallengeSteps;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use Symfony\Component\HttpFoundation\Response;

class ChallengeController extends Controller
{
    private const ONE_RECOVERY_CODE = 'Enter one recovery code. Each code works once.';

    public function __construct(
        private readonly Mfa $mfa,
        private readonly ChallengeService $challenges,
        private readonly UiResponse $ui,
        private readonly TrustedBrowsers $trustedBrowsers,
    ) {}

    public function show(Request $request, RecoveryCodes $recoveryCodes): mixed
    {
        $user = $this->sessionUser($request, $this->mfa);
        $guard = $this->mfa->guardFor($request, $user);

        // Verifying early (the reminder's "Verify now"): before a fixed
        // lifetime window ends (mfa.lifetime), or a trusted browser's trust.
        // A verified user passes the challenge again, then returns to the
        // page they came from; the new verification starts a new window.
        $renew = $request->boolean('renew') && $this->mfa->isVerifiedFor($request, $user)
            && ($this->hasFixedWindow($request, $guard, $user)
                || ($this->trustedBrowsers->offeredTo($user) && $this->trustedBrowsers->runsOnTrustedBrowser($request, $user, $guard)));

        if ($renew && ! $request->header('X-Inertia-Partial-Data')) {
            $previous = url()->previous();
            // Only a page of this app: the Referer is the browser's, and a link
            // from another site must not become where the user lands afterwards.
            if (self::sameHost($previous, $request) && $previous !== url()->current() && ! str_starts_with($previous, route('mfa.challenge'))) {
                $request->session()->put('url.intended', $previous);
            }
        }

        if (! $renew && ($this->mfa->isVerifiedFor($request, $user) || ! $this->mfa->hasConfirmedFactors($user))) {
            // Equivalent mutant(s): home is a string in config.
            return redirect()->intended((string) config('mfa.routes.home')); // @pest-mutate-ignore: RemoveStringCast
        }

        // Enforced users see only their required types (enforcement.required_types),
        // and pass each one they hold; those already passed in this challenge drop out.
        $requirement = $this->mfa->challengeRequirement($user);
        $types = array_map(fn ($t) => $t->value, $requirement['types']);
        $passed = $requirement['all'] ? ChallengeSteps::passed($request->session(), $guard, $user->getAuthIdentifier()) : [];

        $factors = $user->mfaFactors()
            ->whereNotNull('confirmed_at')
            // Equivalent mutant(s): Eloquent binds backed enums by value.
            // Every type still held already passed (one was removed meanwhile): offer them all; any one completes it.
            ->whereIn('type', array_values(array_diff($types, $passed)) ?: $types) // @pest-mutate-ignore: UnwrapArrayMap
            // The authenticator app first (nothing is sent for it), then the rest by last use.
            ->orderByRaw('case when type = ? then 0 else 1 end', [FactorType::Totp->value])
            ->orderByDesc('last_used_at')
            ->get();

        return $this->ui->page('challenge', [
            'factors' => $factors->map(fn (MfaFactor $f) => [...$f->toPublicArray(), ...$this->challengeState($f)->toArray()])->values(),
            // Equivalent mutant(s): the page is only shown when the user has at least one enabled factor.
            'defaultFactorId' => $factors->first()?->id, // @pest-mutate-ignore: RemoveNullSafeOperator
            'hasRecoveryCodes' => $recoveryCodes->remaining($user) > 0,
            // "Don't ask again on this browser for N days" (trusted_browsers); null = not offered.
            'trustBrowser' => $this->trustedBrowsers->offeredTo($user) ? ['days' => $this->trustedBrowsers->days()] : null,
            // Renewing a trusted browser: the page ticks "don't ask again" and offers to go back instead of signing out.
            'renew' => $renew,
            // An enforced user who must pass several types: how many, and which are done. null = any one method.
            'steps' => $requirement['all'] && count($types) > 1 ? ['total' => count($types), 'passed' => array_values(array_intersect($passed, $types))] : null,
            'urls' => [
                'send' => route('mfa.challenge.send'),
                'verify' => route('mfa.challenge.verify'),
                'recover' => route('mfa.challenge.recover'),
                'logout' => $this->logoutUrl(),
            ],
        ]);
    }

    public function send(Request $request): Response
    {
        $validated = $request->validate(['factor_id' => ['required', 'integer']]);
        $user = $this->sessionUser($request, $this->mfa);

        $result = $this->challenges->send($user, $validated['factor_id']);

        if ($result->failed()) {
            $this->ui->failure($result);
        }

        return $this->ui->success(route('mfa.challenge'), 'code-sent', array_filter([
            'retry_after' => $result->context['retry_after'] ?? null,
        ]));
    }

    public function verify(Request $request): Response
    {
        $validated = $request->validate(['factor_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:16'], 'remember' => ['sometimes', 'boolean']]);
        $user = $this->sessionUser($request, $this->mfa);
        $guard = $this->mfa->guardFor($request, $user);

        $result = $this->challenges->verify($user, $validated['factor_id'], $validated['code']);

        if ($result->failed()) {
            $this->ui->failure($result);
        }

        // An enforced user holding several required types passes each one (one code each).
        $requirement = $this->mfa->challengeRequirement($user);
        $type = $result->context['factor'] ?? null;

        if ($requirement['all'] && $type instanceof FactorType) {
            $passed = ChallengeSteps::pass($request->session(), $guard, $user->getAuthIdentifier(), $type);
            $remaining = array_values(array_diff(array_map(fn ($t) => $t->value, $requirement['types']), $passed));

            if ($remaining !== []) {
                event(new ChallengeStepPassed($user, $type, null, ['factor_id' => $result->context['factor_id'] ?? null, 'remaining' => $remaining]));

                // Verifying early ("Verify now"), the next step stays in that mode.
                $next = route('mfa.challenge', $this->mfa->isVerifiedFor($request, $user) ? ['renew' => 1] : []);

                return $this->ui->success($next, 'factor-verified', ['remaining' => $remaining]);
            }
        }

        $this->mfa->markVerified($request, $user, $type, ['factor_id' => $result->context['factor_id'] ?? null]);
        // Only now: a step that leaves another to go must not reset the verify limits.
        $this->challenges->completed($user);

        // "Don't ask again on this browser" (trusted_browsers). Not offered
        // after a recovery code, which often means a lost device.
        if (($validated['remember'] ?? false) && $this->trustedBrowsers->offeredTo($user)) {
            $this->trustedBrowsers->trust($request, $user, $guard);
        }

        $intended = $this->intended($request);

        return $this->ui->leave($intended, 'verified', ['redirect' => $intended]);
    }

    public function recover(Request $request): Response
    {
        // A paste of several codes (the saved list is one per line) gets a
        // plain answer instead of a length error or "invalid code".
        $validated = $request->validate(
            ['code' => ['bail', 'required', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && RecoveryCodes::looksLikeSeveral($value)) {
                    $fail(self::ONE_RECOVERY_CODE);
                }
            }]],
            ['code.max' => self::ONE_RECOVERY_CODE],
        );
        $user = $this->sessionUser($request, $this->mfa);

        $result = $this->challenges->recover($user, $validated['code']);

        if ($result->failed()) {
            $this->ui->failure($result);
        }

        $this->mfa->markVerified($request, $user, null, ['via' => 'recovery_code', 'remaining' => $result->context['remaining']]);

        $intended = $this->intended($request);

        return $this->ui->leave($intended, 'verified-with-recovery-code', [
            'redirect' => $intended,
            'remaining' => $result->context['remaining'],
        ]);
    }

    /**
     * The code already out for an email/SMS factor and its resend cooldown,
     * so a refresh keeps the countdown and the page doesn't send again, and
     * how many digits its codes have.
     */
    private function challengeState(MfaFactor $factor): ChallengeState
    {
        $driver = $factor->type->isDelivered() ? $this->mfa->factor($factor->type) : null;

        return $driver instanceof OtpFactor
            ? $driver->challengeState($factor)
            : ChallengeState::none(TotpFactor::CODE_LENGTH);
    }

    /**
     * Whether a URL is on this app's host: the request's host, or url('/')'s
     * (URL::forceRootUrl()). The scheme is left out (behind a proxy that
     * ends TLS, the browser's https Referer meets an http request), and so
     * is a default port; a port the URL names otherwise must match.
     */
    private static function sameHost(string $url, Request $request): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        $default = ['http' => 80, 'https' => 443][$parts['scheme'] ?? 'http'] ?? null;
        $port = isset($parts['port']) && $parts['port'] !== $default ? $parts['port'] : null;

        foreach ([$request->getSchemeAndHttpHost(), url('/')] as $own) {
            $ownParts = parse_url($own);
            $ownDefault = ['http' => 80, 'https' => 443][$ownParts['scheme'] ?? 'http'] ?? null;
            $ownPort = isset($ownParts['port']) && $ownParts['port'] !== $ownDefault ? $ownParts['port'] : null;

            if (strtolower($parts['host']) === strtolower($ownParts['host'] ?? '') && $port === $ownPort) {
                return true;
            }
        }

        return false;
    }

    private function hasFixedWindow(Request $request, string $guard, MultiFactorAuthenticatable $user): bool
    {
        return ($this->mfa->lifetime()->entry($request->session(), $guard, $user->getAuthIdentifier())['until'] ?? null) !== null;
    }

    private function logoutUrl(): ?string
    {
        $name = config('mfa.routes.logout_route');

        return is_string($name) && Route::has($name) ? route($name) : null;
    }

    private function intended(Request $request): string
    {
        // Equivalent mutant(s): both values are strings.
        return (string) $request->session()->pull('url.intended', config('mfa.routes.home')); // @pest-mutate-ignore: RemoveStringCast
    }
}
