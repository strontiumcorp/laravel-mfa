<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * An enforced user passed one of the required types their challenge asks
 * for (enforcement.required_types: every one they hold), with more to go.
 * Context: factor_id, remaining (the type values still to pass).
 */
final class ChallengeStepPassed extends MfaEvent {}
