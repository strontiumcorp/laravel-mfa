<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Enums\TrustedBrowserRevocation;
use StrontiumCorp\LaravelMfa\Events\PasswordConfirmationFailed;
use StrontiumCorp\LaravelMfa\Events\PasswordConfirmed;
use StrontiumCorp\LaravelMfa\Exceptions\EnrollmentFailed;
use StrontiumCorp\LaravelMfa\Factors\TotpFactor;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\EnrollmentService;
use StrontiumCorp\LaravelMfa\Support\EnrollmentVerification;
use StrontiumCorp\LaravelMfa\Support\PendingEnrollments;
use StrontiumCorp\LaravelMfa\Support\RateLimits;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly EnrollmentService $enrollment,
        private readonly UiResponse $ui,
    ) {}

    public function show(Request $request, RecoveryCodes $recoveryCodes, EnrollmentVerification $verification, TrustedBrowsers $browsers): mixed
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
            // Only the required types for an enforced user (the factor routes refuse the rest).
            'availableTypes' => $this->availableTypes($user),
            'recoveryCodesRemaining' => $recoveryCodes->remaining($user),
            // How many a fresh set has (recovery_codes.count), for the "8 of 10 left" meter.
            'recoveryCodesTotal' => (int) config('mfa.recovery_codes.count'),
            // What the downloaded recovery codes file is named after: the app as
            // authenticator apps show it (factors.totp.issuer, with the environment
            // outside production), and the account (the authenticator app's label).
            // The page adds the date.
            'recoveryCodesFile' => $this->recoveryCodesFile($user),
            'mustEnroll' => $this->mfa->mustEnroll($user),
            // What an enforced user must set up (enforcement.required_types); [] = any type.
            'requiredTypes' => $this->mfa->isEnforced($user)
                ? array_map(fn (FactorType $t) => ['type' => $t->value, 'label' => $t->label()], $this->mfa->requiredTypes())
                : [],
            'urls' => [
                'store' => route('mfa.factors.store'),
                'confirm' => route('mfa.factors.confirm', ['factor' => '__ID__']),
                'resend' => route('mfa.factors.resend', ['factor' => '__ID__']),
                'destroy' => route('mfa.factors.destroy', ['factor' => '__ID__']),
                'recoveryCodes' => route('mfa.recovery-codes.store'),
                'confirmPassword' => route('mfa.password.confirm'),
                'sendEnrollmentCode' => route('mfa.enrollment-verification.send'),
                'verifyEnrollmentCode' => route('mfa.enrollment-verification.verify'),
                'forgetTrustedBrowser' => route('mfa.trusted-browsers.destroy', ['browser' => '__ID__']),
                'forgetTrustedBrowsers' => route('mfa.trusted-browsers.destroy-all'),
            ],
            // Browsers that skip the challenge (trusted_browsers), newest first;
            // "current" is this one. null when the feature is off; [] when none.
            'trustedBrowsers' => config('mfa.trusted_browsers.enabled') ? $browsers->list($request, $user) : null,
            // Adding the first method needs proof of ownership first, and this
            // session hasn't given it (enrollment_verification): where the code
            // goes (masked; null = only an administrator's link works). null =
            // not needed. The factor routes still enforce it.
            'enrollmentVerification' => $verification->required($request->session(), $user) ? $verification->describe($user) : null,
            // Whether adding or removing a factor would ask for the password right
            // now, so the setup dialog can show that step from the start. The
            // factor routes still enforce it (RequirePasswordConfirmation).
            'passwordConfirmationRequired' => $this->mfa->requiresPasswordConfirmation($user)
                && ! $this->mfa->passwordRecentlyConfirmed($request->session()),
            // Seconds until the password prompt may be tried again (its own countdown).
            'passwordRetryAfter' => $request->session()->get(UiResponse::PASSWORD_RETRY_AFTER),
            // The nudge's title and body, shown as a notice to the users the
            // nudge is for (Mfa::nudgeEligible(); a dismissal doesn't hide it).
            'nudge' => $this->mfa->nudgeEligible($user)
                ? array_intersect_key($this->mfa->nudgeCopy(), ['title' => true, 'body' => true])
                : null,
        ]);
    }

    /** The labels of the types this user may add, e.g. "Authenticator app and Email". */
    private function labels(MultiFactorAuthenticatable $user): string
    {
        $labels = array_map(fn (FactorType $t) => $t->label(), $this->mfa->enrollableTypes($user));
        $last = array_pop($labels);

        return $labels === [] ? (string) $last : implode(', ', $labels).' and '.$last;
    }

    /** @return array{app: string, slug: string, account: string} */
    private function recoveryCodesFile(MultiFactorAuthenticatable $user): array
    {
        $app = TotpFactor::issuerFor((array) config('mfa.factors.totp'), (string) config('app.env'));

        return ['app' => $app, 'slug' => Str::slug($app), 'account' => $user->getMfaLabel()];
    }

    /**
     * The types this user may add (Mfa::enrollableTypes()), recommended ones
     * first (otherwise in enum order).
     *
     * @return list<array{type: string, label: string, recommended: bool}>
     */
    private function availableTypes(MultiFactorAuthenticatable $user): array
    {
        return collect($this->mfa->enrollableTypes($user))
            ->map(fn (FactorType $t) => ['type' => $t->value, 'label' => $t->label(), 'recommended' => $this->mfa->isTypeRecommended($t)])
            ->sortBy(fn (array $t) => $t['recommended'] ? 0 : 1)
            ->values()
            ->all();
    }

    public function store(Request $request): Response
    {
        $user = $this->sessionUser($request, $this->mfa);
        // An enforced user may add only the required types (enforcement.required_types).
        // Equivalent mutant(s): Rule::in() accepts backed enums.
        $allowed = array_map(fn (FactorType $t) => $t->value, $this->mfa->enrollableTypes($user)); // @pest-mutate-ignore: UnwrapArrayMap

        $validated = $request->validate([
            'type' => ['required', Rule::in($allowed)],
            'destination' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:100'],
        ], ['type.in' => __('Your account can only use :types.', ['types' => $this->labels($user)])]);

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

        // A setup started before the user became enforced can't finish as a type they may not use.
        $type = $user->mfaFactors()->whereKey($factor)->value('type');
        $type = $type instanceof FactorType ? $type : FactorType::tryFrom((string) $type);
        if ($type !== null && ! in_array($type, $this->mfa->enrollableTypes($user), true)) {
            throw ValidationException::withMessages(['code' => __('Your account can only use :types.', ['types' => $this->labels($user)])]);
        }

        $confirmation = $this->enrollment->confirm($user, $factor, $validated['code']);

        if ($confirmation['result']->failed()) {
            $this->ui->failure($confirmation['result']);
        }

        // Proving possession of the new factor satisfies MFA for this session.
        if (! $this->mfa->isVerifiedFor($request, $user)) {
            $this->mfa->markVerified($request, $user, $confirmation['result']->context['factor'] ?? null, ['stage' => 'enrollment']);
        } else {
            $this->mfa->refreshEnrollmentRequirement($request, $user);
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
        $user = $this->sessionUser($request, $this->mfa);
        $this->enrollment->disable($user, $factor);
        $this->mfa->refreshEnrollmentRequirement($request, $user);

        return $this->ui->success(route('mfa.settings'), 'factor-disabled');
    }

    /** Stop trusting one browser (DELETE trusted-browsers/{browser}) or all of them. */
    public function forgetTrustedBrowsers(Request $request, TrustedBrowsers $browsers, ?string $browser = null): Response
    {
        $browsers->forget($this->sessionUser($request, $this->mfa), TrustedBrowserRevocation::Settings, $browser === null ? null : (int) $browser);

        return $this->ui->success(route('mfa.settings'), 'trusted-browsers-forgotten');
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

    /**
     * The settings page's own password prompt (routes.password_confirmation):
     * checks the password with the session guard's user provider and records
     * it like Laravel's confirm-password page does (auth.password_confirmed_at).
     */
    public function confirmPassword(Request $request, RateLimits $limits): Response
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:1000']]);
        $user = $this->sessionUser($request, $this->mfa);

        if (! $limits->attemptPassword($user)) {
            $retryAfter = $limits->passwordAvailableIn($user);
            event(new PasswordConfirmationFailed($user, null, FailureReason::RateLimited, ['retry_after' => $retryAfter]));
            $this->ui->failure(VerificationResult::failure(FailureReason::RateLimited, ['retry_after' => $retryAfter]), 'password');
        }

        if (! $this->mfa->validatePassword($request, $user, $validated['password'])) {
            event(new PasswordConfirmationFailed($user, null, FailureReason::InvalidPassword));
            $this->ui->failure(FailureReason::InvalidPassword, 'password');
        }

        $limits->clearPassword($user);
        $this->mfa->markPasswordConfirmed($request->session());
        event(new PasswordConfirmed($user));

        return $this->ui->success(route('mfa.settings'), 'password-confirmed');
    }
}
