<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\VerificationFailed;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\EnrollmentLinks;
use StrontiumCorp\LaravelMfa\Support\EnrollmentVerification;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proof of ownership before an account's first factor (enrollment_verification):
 * the email code, and the administrator's one-time link.
 */
class EnrollmentVerificationController extends Controller
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly EnrollmentVerification $verification,
        private readonly UiResponse $ui,
    ) {}

    public function send(Request $request): Response
    {
        $user = $this->sessionUser($request, $this->mfa);

        if (! $this->verification->required($request->session(), $user)) {
            return $this->ui->success(route('mfa.settings'), 'enrollment-verified');
        }

        $result = $this->verification->send($request->session(), $user);

        if ($result->failed()) {
            // Delivery failures already emitted ChallengeDeliveryFailed.
            if ($result->reason !== FailureReason::DeliveryFailed) {
                event(new VerificationFailed($user, FactorType::Email, $result->reason, ['stage' => 'enrollment_verification_send', ...$result->context]));
            }

            $this->ui->failure($result);
        }

        return $this->ui->success(route('mfa.settings'), 'enrollment-code-sent', [
            'retry_after' => $result->context['retry_after'],
            ...$this->verification->describe($user),
        ]);
    }

    public function verify(Request $request): Response
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $user = $this->sessionUser($request, $this->mfa);

        if (! $this->verification->required($request->session(), $user)) {
            return $this->ui->success(route('mfa.settings'), 'enrollment-verified');
        }

        $result = $this->verification->verify($request->session(), $user, $validated['code']);

        if ($result->failed()) {
            event(new VerificationFailed($user, FactorType::Email, $result->reason, ['stage' => 'enrollment_verification', ...$result->context]));

            $this->ui->failure($result);
        }

        return $this->ui->success(route('mfa.settings'), 'enrollment-verified');
    }

    /**
     * GET, signed: an administrator's link (Mfa::enrollmentLink()), opened by
     * the user while signed in. A page visit in any UI mode, so it always
     * redirects to the settings page.
     */
    public function link(Request $request, EnrollmentLinks $links, string $user): Response
    {
        $sessionUser = $this->sessionUser($request, $this->mfa);

        if ($this->verification->required($request->session(), $sessionUser)) {
            $problem = $links->redeem(
                $request->session(),
                $sessionUser,
                $user,
                (string) $request->query('binding'),
                (string) $request->query('signature'),
                (int) $request->query('expires'),
            );

            if ($problem !== null) {
                event(new VerificationFailed($sessionUser, null, FailureReason::InvalidLink, ['stage' => 'enrollment_link', 'problem' => $problem->value]));
                abort(403, $problem->message());
            }
        }

        return redirect()->route('mfa.settings')->with('mfa.status', 'enrollment-verified');
    }
}
