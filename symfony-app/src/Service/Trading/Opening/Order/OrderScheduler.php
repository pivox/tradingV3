<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Order;

use App\Service\Bitmart\Private\TrailOrdersService;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class OrderScheduler
{
    public function __construct(
        private readonly TrailOrdersService $trailOrders,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scheduleCancelAll(string $symbol, int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 0) {
            throw new RuntimeException('timeoutSeconds doit être >= 0 (0 pour désactiver)');
        }
        if ($timeoutSeconds !== 0 && $timeoutSeconds < 5) {
            throw new RuntimeException('timeoutSeconds doit être >= 5 (ou 0 pour annuler)');
        }

        $this->logger->info('[Opening] schedule cancel-all-after', [
            'symbol' => $symbol,
            'timeout' => $timeoutSeconds,
        ]);

        $response = $this->trailOrders->cancelAllAfter($symbol, $timeoutSeconds);
        if (($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('cancel-all-after error: ' . json_encode($response, JSON_UNESCAPED_SLASHES));
        }
    }
}
