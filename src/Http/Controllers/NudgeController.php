<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Events\NudgeDismissed;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\Nudge;
use Symfony\Component\HttpFoundation\Response;

class NudgeController extends Controller
{
    /**
     * "Not today" on the turn-on-two-factor nudge: hidden until the next
     * midnight in the browser's timezone (the only input; the time itself is
     * always computed here).
     */
    public function dismiss(Request $request, Mfa $mfa, Nudge $nudge, UiResponse $ui): Response
    {
        $user = $this->sessionUser($request, $mfa);
        $until = $nudge->dismiss($request->session(), $user, $request->input('timezone'))->toIso8601String();

        event(new NudgeDismissed($user, null, null, ['until' => $until]));

        return $ui->backQuietly('nudge-dismissed', ['until' => $until]);
    }
}
