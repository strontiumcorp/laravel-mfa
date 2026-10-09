<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Events\NudgeDismissed;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\Nudge;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
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

    /** "Later" on the trusted browser's renewal reminder: hidden for the rest of this session. */
    public function dismissTrustReminder(Request $request, TrustedBrowsers $browsers, UiResponse $ui): Response
    {
        $browsers->dismissReminder($request);

        return $ui->backQuietly('trust-reminder-dismissed');
    }
}
