<?php

namespace StrontiumCorp\LaravelMfa\Http;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * One place that knows about Inertia vs JSON. Controllers never branch on it.
 */
final class UiResponse
{
    public function __construct(private readonly Request $request) {}

    /** @param array<string, mixed> $props */
    public function page(string $page, array $props): mixed
    {
        $props += [
            'status' => $this->request->session()->get('mfa.status'),
            'recoveryCodes' => $this->request->session()->get('mfa.recovery_codes'),
            // Seconds until another code can be requested (drives the countdown).
            'retryAfter' => $this->request->session()->get('mfa.retry_after'),
        ];

        if ($this->wantsJson() || ! class_exists(Inertia::class)) {
            return response()->json($props);
        }

        // Equivalent mutant(s): page names are strings in config.
        return Inertia::render((string) config("mfa.ui.pages.{$page}"), $props); // @pest-mutate-ignore: RemoveStringCast
    }

    /**
     * @param  array<string, mixed>  $data  returned as JSON, or flashed (status, recovery codes)
     */
    public function success(string $redirectTo, string $status, array $data = []): Response
    {
        if ($this->wantsJson()) {
            return response()->json(['status' => $status, ...$data]);
        }

        $redirect = redirect()->to($redirectTo)->with('mfa.status', $status);

        if (isset($data['recovery_codes'])) {
            $redirect->with('mfa.recovery_codes', $data['recovery_codes']);
        }

        if (isset($data['retry_after'])) {
            $redirect->with('mfa.retry_after', $data['retry_after']);
        }

        return $redirect;
    }

    /**
     * Failures become validation errors on "code": 422 for JSON, redirect-back
     * with errors for Inertia — both handled natively by Laravel.
     *
     * @throws ValidationException
     */
    public function failure(VerificationResult|FailureReason $failure, string $field = 'code'): never
    {
        $reason = $failure instanceof FailureReason ? $failure : ($failure->reason ?? FailureReason::InvalidCode);
        // Equivalent mutant(s): for a bare FailureReason the ?? on a missing property already yields null.
        $retryAfter = $failure instanceof VerificationResult ? ($failure->context['retry_after'] ?? null) : null; // @pest-mutate-ignore: InstanceOfToTrue

        $status = match ($reason) {
            FailureReason::RateLimited, FailureReason::Cooldown, FailureReason::DestinationLimit => 429,
            FailureReason::SendingPaused => 503,
            default => 422,
        };

        if ($this->wantsJson()) {
            // Same shape as a validation error, plus retry_after when known.
            throw new HttpResponseException(response()->json(array_filter([
                'message' => $reason->message(),
                'errors' => [$field => [$reason->message()]],
                'retry_after' => $retryAfter,
            ], fn ($value) => $value !== null), $status));
        }

        if ($retryAfter !== null) {
            $this->request->session()->flash('mfa.retry_after', $retryAfter);
        }

        throw ValidationException::withMessages([$field => $reason->message()])->status($status);
    }

    private function wantsJson(): bool
    {
        return config('mfa.ui.driver') === 'json'
            // Equivalent mutant(s): for X-Inertia requests that also accept JSON, Laravel renders the validation exception as JSON either way.
            || ($this->request->expectsJson() && ! $this->request->header('X-Inertia')); // @pest-mutate-ignore: RemoveNot
    }
}
