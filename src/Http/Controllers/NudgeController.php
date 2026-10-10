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
     * always computed here). A repeat while it is hidden is a success that
     * changes nothing and records nothing.
     */
    public function dismiss(Request $request, Mfa $mfa, Nudge $nudge, UiResponse $ui): Response
    {
        $user = $this->sessionUser($request, $mfa);
        $dismissal = $nudge->dismiss($request->session(), $user, $request->input('timezone'));
        $until = $dismissal['until']->toIso8601String();

        if ($dismissal['changed']) {
            event(new NudgeDismissed($user, null, null, ['until' => $until]));
        }

        return $ui->backQuietly('nudge-dismissed', ['until' => $until]);
    }

    /**
     * "Later" on the "check coming up" reminder (the lifetime window's end,
     * or a trusted browser's): hidden until the next verification.
     */
    public function dismissReminder(Request $request, UiResponse $ui): Response
    {
        $request->session()->put(Mfa::REMINDER_DISMISSED, true);

        return $ui->backQuietly('reminder-dismissed');
    }
}
