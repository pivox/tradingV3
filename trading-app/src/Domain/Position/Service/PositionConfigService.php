<?php

declare(strict_types=1);

namespace App\Domain\Position\Service;

use App\Domain\Position\Dto\PositionConfigDto;
use Psr\Log\LoggerInterface;

class PositionConfigService
{
    private PositionConfigDto $config;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        $this->loadConfig();
    }

    public function getConfig(): PositionConfigDto
    {
        return $this->config;
    }

    private function loadConfig(): void
    {
        // Configuration basée sur trading.yml
        $this->config = new PositionConfigDto(
            defaultRiskPercent: 2.0,
            maxRiskPercent: 5.0,
            slAtrMultiplier: 2.0,
            tpAtrMultiplier: 4.0,
            maxPositionSize: 5000.0,
            orderType: 'MARKET',
            timeInForce: 'GTC',
            enablePartialFills: true,
            minOrderSize: 5.0,
            maxOrderSize: 10000.0,
            enableStopLoss: true,
            enableTakeProfit: true,
            openType: 'cross'
        );

        $this->logger->info('[Position Config] Configuration loaded', [
            'order_type' => $this->config->orderType,
            'max_position_size' => $this->config->maxPositionSize,
            'default_risk_percent' => $this->config->defaultRiskPercent
        ]);
    }
}
