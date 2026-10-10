<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Mfa;
use Symfony\Component\HttpFoundation\Response;

/**
 * The verified session's deadlines (mfa.lifetime), for the frontend's idle
 * warning. Both run behind the gate, so an expired verification answers 403
 * like any other request.
 */
class VerificationController extends Controller
{
    /** The deadlines as they are now (another tab may have been active). Never counts as activity. */
    public function show(Request $request, Mfa $mfa): JsonResponse
    {
        $identity = $mfa->sessionIdentity($request);
        $entry = $identity === null ? null : $mfa->lifetime()->entry($request->session(), $identity->guard, $identity->id);

        return response()->json([
            'verification' => $entry === null || $identity === null ? null : $mfa->lifetime()->describe($request->session(), $identity->guard, $identity->id, $entry),
        ]);
    }

    /** "Stay signed in" on the idle warning: the gate has already counted it as activity. */
    public function keepAlive(): Response
    {
        return response()->noContent();
    }
}
