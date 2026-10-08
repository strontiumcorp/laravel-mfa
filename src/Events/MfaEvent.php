<?php

namespace StrontiumCorp\LaravelMfa\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Support\Redact;
use StrontiumCorp\LaravelMfa\Support\RequestContext;

abstract class MfaEvent implements MfaActivity
{
    public readonly ?string $flowId;

    public readonly ?string $ipAddress;

    public readonly ?string $userAgent;

    public readonly CarbonImmutable $occurredAt;

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly ?FactorType $factorType = null,
        public readonly ?FailureReason $reason = null,
        public readonly array $context = [],
    ) {
        $this->flowId = Context::get(RequestContext::FLOW_ID);
        $this->ipAddress = Context::getHidden(RequestContext::IP);
        $this->userAgent = Context::getHidden(RequestContext::USER_AGENT);
        $this->occurredAt = CarbonImmutable::now();
    }

    public function name(): string
    {
        return Str::snake(class_basename(static::class));
    }

    public function level(): string
    {
        return $this->reason === null ? 'info' : 'warning';
    }

    public function toContext(): array
    {
        return array_filter([
            'event' => $this->name(),
            'user_type' => $this->user ? $this->user::class : null,
            'user_id' => $this->user?->getAuthIdentifier(),
            'factor' => $this->factorType?->value,
            'reason' => $this->reason?->value,
            'flow_id' => $this->flowId,
            'ip' => $this->ipAddress,
            // Free text (e.g. provider errors) is redacted before any sink sees it.
            ...Redact::context($this->context),
        ], static fn ($value) => $value !== null);
    }
}
