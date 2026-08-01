<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Exchange\Event\ExchangeEventInterface;
use App\TradeEntry\Dto\ExecutionResult;

final readonly class PaperFakeDispatchResult
{
    /** @param list<ExchangeEventInterface> $events */
    public function __construct(
        public ExecutionResult $execution,
        public array $events,
        public bool $idempotentReplay,
    ) {
    }
}
