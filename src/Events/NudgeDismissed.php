<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * The user chose "Not today" on the turn-on-two-factor nudge. Context:
 * "until", when it shows again (ISO 8601, app timezone).
 */
final class NudgeDismissed extends MfaEvent
{
    //
}
