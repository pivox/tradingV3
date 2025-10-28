<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement d'audit MTF
 */
class MtfAuditEvent extends Event
{
    public const NAME = 'mtf.audit.step';

    public function __construct(
        private readonly string $symbol,
        private readonly string $step,
        private readonly ?string $message = null,
        private readonly array $data = [],
        private readonly int $severity = 0,
    ) {
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getSeverity(): int
    {
        return $this->severity;
    }
}
