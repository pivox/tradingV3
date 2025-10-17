<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Service;

use App\Domain\Leverage\Dto\LeverageConfigDto;
use Psr\Log\LoggerInterface;

class LeverageConfigService
{
    private LeverageConfigDto $config;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        $this->loadConfig();
    }

    public function getConfig(): LeverageConfigDto
    {
        return $this->config;
    }

    private function loadConfig(): void
    {
        // Configuration basée sur trading.yml
        $confidenceMultiplier = new \App\Domain\Leverage\Dto\ConfidenceMultiplierConfig(
            enabled: true,
            default: 1.0,
            whenUpstreamStale: 0.8,
            whenTieBreakerUsed: 0.8
        );

        $conviction = new \App\Domain\Leverage\Dto\ConvictionConfig(
            enabled: true,
            capPctOfExchange: 0.60
        );

        $rounding = new \App\Domain\Leverage\Dto\RoundingConfig(
            precision: 2,
            mode: 'floor'
        );

        $this->config = new LeverageConfigDto(
            mode: 'dynamic_from_risk',
            floor: 1.0,
            exchangeCap: 20.0,
            perSymbolCaps: [
                ['symbol_regex' => '^(BTC|ETH).*', 'cap' => 10.0],
                ['symbol_regex' => '^(ADA|SOL|DOT).*', 'cap' => 15.0],
            ],
            confidenceMultiplier: $confidenceMultiplier,
            conviction: $conviction,
            rounding: $rounding
        );

        $this->logger->info('[Leverage Config] Configuration loaded', [
            'mode' => $this->config->mode,
            'floor' => $this->config->floor,
            'exchange_cap' => $this->config->exchangeCap
        ]);
    }
}
