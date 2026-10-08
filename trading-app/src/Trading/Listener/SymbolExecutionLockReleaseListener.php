<?php

declare(strict_types=1);

namespace App\Trading\Listener;

use App\Provider\Context\UnsupportedExchangeException;
use App\Provider\Context\ExchangeContext;
use App\Service\SymbolExecutionLockManager;
use App\Trading\Event\PositionClosedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: PositionClosedEvent::class)]
final class SymbolExecutionLockReleaseListener
{
    public function __construct(
        private readonly SymbolExecutionLockManager $symbolExecutionLockManager,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(PositionClosedEvent $event): void
    {
        $marketType = $event->extra['market_type'] ?? $event->extra['marketType'] ?? null;
        try {
            $context = ExchangeContext::fromValues($event->exchange, $marketType);
        } catch (UnsupportedExchangeException $e) {
            $this->logger?->warning('symbol_lock.release_unsupported_exchange_skipped', ['exchange' => $e->rawValue]);

            return;
        }

        $this->symbolExecutionLockManager->releaseForSymbol(
            $event->positionHistory->symbol,
            $context,
            'position_closed',
            true,
            $event->positionHistory->closedAt,
        );
    }
}
