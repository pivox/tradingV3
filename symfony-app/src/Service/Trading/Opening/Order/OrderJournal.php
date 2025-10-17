<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Order;

use App\Service\Pipeline\MtfStateService;
use Psr\Log\LoggerInterface;
use Throwable;

final class OrderJournal
{
    public function __construct(
        private readonly MtfStateService $mtfState,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(string $symbol, ?string $orderId, string $side, string $context): void
    {
        if ($orderId === null || trim($orderId) === '') {
            return;
        }

        $intent = strtolower($side) === 'short' ? 'OPEN_SHORT' : 'OPEN_LONG';

        try {
            $this->mtfState->recordOrder($orderId, $symbol, $intent);
            $this->logger->info(sprintf('[Opening] %s order persisted', $context), [
                'symbol' => $symbol,
                'order_id' => $orderId,
            ]);
        } catch (Throwable $error) {
            $this->logger->warning(sprintf('[Opening] %s order persist failed', $context), [
                'symbol' => $symbol,
                'order_id' => $orderId,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
