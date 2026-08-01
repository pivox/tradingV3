<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Common\Enum\Exchange;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class PaperRuntimeGuard
{
    private const ALLOWED_SYMBOLS = ['BTCUSDT', 'ETHUSDT'];

    public function assertSafe(#[\SensitiveParameter] PaperRuntimeContext $context): void
    {
        if ($context->executionMode !== 'paper') {
            throw new \LogicException('paper_execution_mode_required');
        }

        if ($context->executionExchange !== Exchange::FAKE) {
            throw new \LogicException('paper_execution_exchange_must_be_fake');
        }

        if (!$context->paperExecutionEnabled) {
            throw new \LogicException('paper_execution_disabled');
        }

        if ($context->mainnetWriteEnabled || $context->demoTestnetWriteEnabled) {
            throw new \LogicException('paper_exchange_writes_must_be_disabled');
        }

        if ($context->symbols === []) {
            throw new \LogicException('paper_symbol_not_allowed');
        }

        foreach ($context->symbols as $symbol) {
            if (!in_array($symbol, self::ALLOWED_SYMBOLS, true)) {
                throw new \LogicException('paper_symbol_not_allowed');
            }
        }
    }

    public function assertEventProvenance(
        #[\SensitiveParameter] PaperRuntimeContext $context,
        #[\SensitiveParameter] PaperMarketEvent $event,
    ): void {
        if ($event->sourceNetwork !== $context->cell->network) {
            throw new \LogicException('paper_execution_network_mismatch');
        }

        if ($event->sourceVenue !== $context->cell->marketDataVenue) {
            throw new \LogicException('paper_execution_market_data_venue_mismatch');
        }
    }
}
