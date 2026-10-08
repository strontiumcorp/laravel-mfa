<?php

namespace StrontiumCorp\LaravelMfa\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Correlates everything that happens during one MFA flow.
 *
 * The flow id lives in the session for the duration of a challenge and is
 * pushed into Laravel's Context, so it is appended to every log line written
 * during the request (yours included) and propagates into queued jobs such
 * as code delivery.
 */
final class RequestContext
{
    public const FLOW_ID = 'mfa_flow_id';

    public const IP = 'mfa_ip';

    public const USER_AGENT = 'mfa_user_agent';

    private const SESSION_KEY = 'mfa.flow_id';

    public static function bind(Request $request): string
    {
        $flowId = null;

        if ($request->hasSession()) {
            $flowId = $request->session()->get(self::SESSION_KEY);

            if (! is_string($flowId)) {
                $flowId = (string) Str::uuid();
                $request->session()->put(self::SESSION_KEY, $flowId);
            }
        }

        $flowId ??= (string) Str::uuid();

        Context::add(self::FLOW_ID, $flowId);
        Context::addHidden(self::IP, $request->ip());
        // Equivalent mutant(s): Str::limit() treats a missing user agent as an empty string.
        Context::addHidden(self::USER_AGENT, Str::limit((string) $request->userAgent(), 250, '')); // @pest-mutate-ignore: RemoveStringCast

        return $flowId;
    }

    public static function end(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
