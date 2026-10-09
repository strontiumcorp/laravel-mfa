<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use StrontiumCorp\LaravelMfa\Factors\OtpFactor;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\ChallengeService;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use Symfony\Component\HttpFoundation\Response;

class ChallengeController extends Controller
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly ChallengeService $challenges,
        private readonly UiResponse $ui,
    ) {}

    public function show(Request $request, RecoveryCodes $recoveryCodes): mixed
    {
        $user = $this->sessionUser($request, $this->mfa);

        if ($this->mfa->isVerifiedFor($request, $user) || ! $this->mfa->hasConfirmedFactors($user)) {
            // Equivalent mutant(s): home is a string in config.
            return redirect()->intended((string) config('mfa.routes.home')); // @pest-mutate-ignore: RemoveStringCast
        }

        $factors = $user->mfaFactors()
            ->whereNotNull('confirmed_at')
            // Enforced users see only their required types (enforcement.required_types).
            // Equivalent mutant(s): Eloquent binds backed enums by value.
            ->whereIn('type', array_map(fn ($t) => $t->value, $this->mfa->challengeTypes($user))) // @pest-mutate-ignore: UnwrapArrayMap
            ->orderByDesc('last_used_at')
            ->get();

        return $this->ui->page('challenge', [
            'factors' => $factors->map(fn (MfaFactor $f) => [...$f->toPublicArray(), ...$this->sendState($f)])->values(),
            // Equivalent mutant(s): the page is only shown when the user has at least one enabled factor.
            'defaultFactorId' => $factors->first()?->id, // @pest-mutate-ignore: RemoveNullSafeOperator
            'hasRecoveryCodes' => $recoveryCodes->remaining($user) > 0,
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
        $validated = $request->validate(['factor_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:16']]);
        $user = $this->sessionUser($request, $this->mfa);

        $result = $this->challenges->verify($user, $validated['factor_id'], $validated['code']);

        if ($result->failed()) {
            $this->ui->failure($result);
        }

        $this->mfa->markVerified($request, $user, $result->context['factor'] ?? null, ['factor_id' => $result->context['factor_id'] ?? null]);

        $intended = $this->intended($request);

        return $this->ui->leave($intended, 'verified', ['redirect' => $intended]);
    }

    public function recover(Request $request): Response
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
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
     * so a refresh keeps the countdown and the page doesn't send again.
     *
     * @return array{code_sent: bool, retry_after: int|null}
     */
    private function sendState(MfaFactor $factor): array
    {
        $driver = $factor->type->isDelivered() ? $this->mfa->factor($factor->type) : null;

        return $driver instanceof OtpFactor ? $driver->sendState($factor) : ['code_sent' => false, 'retry_after' => null];
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
