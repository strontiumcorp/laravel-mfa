<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Factors\OtpFactor;
use StrontiumCorp\LaravelMfa\Factors\TotpFactor;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\ChallengeService;
use StrontiumCorp\LaravelMfa\Support\ChallengeState;
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

        // Renewing a trusted browser before it expires (the reminder's
        // "Verify now"): a verified user passes the challenge early, then
        // returns to the page they came from.
        $renew = $request->boolean('renew') && $this->mfa->isVerifiedFor($request, $user)
            && $this->trustedBrowsers->offeredTo($user) && $this->trustedBrowsers->runsOnTrustedBrowser($request, $user, $guard);

        if ($renew && ! $request->header('X-Inertia-Partial-Data')) {
            $previous = url()->previous();
            if ($previous !== url()->current() && ! str_starts_with($previous, route('mfa.challenge'))) {
                $request->session()->put('url.intended', $previous);
            }
        }

        if (! $renew && ($this->mfa->isVerifiedFor($request, $user) || ! $this->mfa->hasConfirmedFactors($user))) {
            // Equivalent mutant(s): home is a string in config.
            return redirect()->intended((string) config('mfa.routes.home')); // @pest-mutate-ignore: RemoveStringCast
        }

        $factors = $user->mfaFactors()
            ->whereNotNull('confirmed_at')
            // Enforced users see only their required types (enforcement.required_types).
            // Equivalent mutant(s): Eloquent binds backed enums by value.
            ->whereIn('type', array_map(fn ($t) => $t->value, $this->mfa->challengeTypes($user))) // @pest-mutate-ignore: UnwrapArrayMap
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

        $this->mfa->markVerified($request, $user, $result->context['factor'] ?? null, ['factor_id' => $result->context['factor_id'] ?? null]);

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
