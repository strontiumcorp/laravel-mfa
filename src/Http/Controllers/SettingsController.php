<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Exceptions\EnrollmentFailed;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\EnrollmentService;
use StrontiumCorp\LaravelMfa\Support\PendingEnrollments;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly EnrollmentService $enrollment,
        private readonly UiResponse $ui,
    ) {}

    public function show(Request $request, RecoveryCodes $recoveryCodes): mixed
    {
        $user = $this->sessionUser($request, $this->mfa);
        $factors = $user->mfaFactors()->orderBy('id')->get();

        $pending = $factors
            ->reject(fn (MfaFactor $f) => $f->isConfirmed())
            ->filter(fn (MfaFactor $f) => $this->mfa->isTypeEnabled($f->type)
                // Equivalent mutant(s): created_at is always set on stored factors.
                && $f->created_at?->gt(now()->subMinutes(PendingEnrollments::TTL_MINUTES)) // @pest-mutate-ignore: RemoveNullSafeOperator
                && PendingEnrollments::owns($request->session(), $f->getKey()))
            ->map(fn (MfaFactor $f) => $this->enrollment->pendingSetup($user, $f))
            ->values();

        return $this->ui->page('settings', [
            'factors' => $factors->filter(fn (MfaFactor $f) => $f->isConfirmed())->map(fn (MfaFactor $f) => $f->toPublicArray())->values(),
            'pending' => $pending,
            'availableTypes' => array_map(fn (FactorType $t) => ['type' => $t->value, 'label' => $t->label()], $this->mfa->enabledTypes()),
            'recoveryCodesRemaining' => $recoveryCodes->remaining($user),
            'mustEnroll' => $this->mfa->mustEnroll($user),
            'urls' => [
                'store' => route('mfa.factors.store'),
                'confirm' => route('mfa.factors.confirm', ['factor' => '__ID__']),
                'resend' => route('mfa.factors.resend', ['factor' => '__ID__']),
                'destroy' => route('mfa.factors.destroy', ['factor' => '__ID__']),
                'recoveryCodes' => route('mfa.recovery-codes.store'),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        // Equivalent mutant(s): Rule::in() accepts backed enums.
        $enabled = array_map(fn (FactorType $t) => $t->value, $this->mfa->enabledTypes()); // @pest-mutate-ignore: UnwrapArrayMap

        $validated = $request->validate([
            'type' => ['required', Rule::in($enabled)],
            'destination' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $this->sessionUser($request, $this->mfa);

        try {
            $enrollment = $this->enrollment->start($user, FactorType::from($validated['type']), $validated);
        } catch (EnrollmentFailed $e) {
            $this->ui->failure(VerificationResult::failure($e->reason, $e->context), 'destination');
        }

        PendingEnrollments::add($request->session(), $enrollment['factor']->getKey());

        return $this->ui->success(route('mfa.settings'), 'enrollment-started', array_filter([
            'factor' => $enrollment['factor']->toPublicArray(),
            'setup' => $enrollment['setup'],
            'retry_after' => $enrollment['setup']['retry_after'] ?? null,
        ]));
    }

    public function confirm(Request $request, string $factor): Response
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $user = $this->sessionUser($request, $this->mfa);

        if (! PendingEnrollments::owns($request->session(), $factor)) {
            $this->ui->failure(FailureReason::FactorNotFound);
        }

        $confirmation = $this->enrollment->confirm($user, $factor, $validated['code']);

        if ($confirmation['result']->failed()) {
            $this->ui->failure($confirmation['result']);
        }

        // Proving possession of the new factor satisfies MFA for this session.
        if (! $this->mfa->isVerifiedFor($request, $user)) {
            $this->mfa->markVerified($request, $user, $confirmation['result']->context['factor'] ?? null, ['stage' => 'enrollment']);
        }

        return $this->ui->success(route('mfa.settings'), 'factor-enabled', array_filter([
            'recovery_codes' => $confirmation['recovery_codes'],
        ]));
    }

    public function resend(Request $request, string $factor): Response
    {
        if (! PendingEnrollments::owns($request->session(), $factor)) {
            $this->ui->failure(FailureReason::FactorNotFound);
        }

        $result = $this->enrollment->resend($this->sessionUser($request, $this->mfa), $factor);

        if ($result->failed()) {
            $this->ui->failure($result);
        }

        // Equivalent mutant(s): a successful resend always has retry_after.
        return $this->ui->success(route('mfa.settings'), 'code-sent', array_filter([ // @pest-mutate-ignore: UnwrapArrayFilter
            'retry_after' => $result->context['retry_after'] ?? null,
        ]));
    }

    public function destroy(Request $request, string $factor): Response
    {
        $this->enrollment->disable($this->sessionUser($request, $this->mfa), $factor);

        return $this->ui->success(route('mfa.settings'), 'factor-disabled');
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = $this->sessionUser($request, $this->mfa);

        if (! $this->mfa->hasConfirmedFactors($user)) {
            abort(422, 'Enable a verification method first.');
        }

        return $this->ui->success(route('mfa.settings'), 'recovery-codes-generated', [
            'recovery_codes' => $this->enrollment->regenerateRecoveryCodes($user),
        ]);
    }
}
