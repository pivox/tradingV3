<?php

namespace App\Service\Config;

use App\Entity\TradingConfiguration;
use App\Repository\TradingConfigurationRepository;
use Symfony\Component\Yaml\Yaml;

class TradingParameters
{
    private ?array $cache = null;

    public function __construct(
        private readonly string $configFile,
        private readonly TradingConfigurationRepository $configurationRepository,
    ) {}

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function getConfig(): array
    {
        return $this->all();
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $config = Yaml::parseFile($this->configFile);
        if (!\is_array($config)) {
            $config = [];
        }

        $rows = $this->configurationRepository->findAllByScope();
        foreach ($rows as $row) {
            $context = $row->getContext();

            if ($context === TradingConfiguration::CONTEXT_GLOBAL || $context === TradingConfiguration::CONTEXT_STRATEGY) {
                $config['budget']['open_cap_usdt'] = $row->getBudgetCapUsdt() ?? ($config['budget']['open_cap_usdt'] ?? null);
                $config['risk']['abs_usdt'] = $row->getRiskAbsUsdt() ?? ($config['risk']['abs_usdt'] ?? null);
                $config['tp']['abs_usdt'] = $row->getTpAbsUsdt() ?? ($config['tp']['abs_usdt'] ?? null);
            }

            if ($context === TradingConfiguration::CONTEXT_EXECUTION) {
                $config['execution']['budget_cap_usdt'] = $row->getBudgetCapUsdt() ?? ($config['execution']['budget_cap_usdt'] ?? null);
                $config['execution']['risk_abs_usdt'] = $row->getRiskAbsUsdt() ?? ($config['execution']['risk_abs_usdt'] ?? null);
                $config['execution']['tp_abs_usdt'] = $row->getTpAbsUsdt() ?? ($config['execution']['tp_abs_usdt'] ?? null);
            }

            if ($context === TradingConfiguration::CONTEXT_SECURITY) {
                $banned = $row->getBannedContracts();
                if ($banned !== []) {
                    $config['security']['banned_contracts'] = $banned;
                }
            }
        }

        return $this->cache = $config;
    }

    public function riskPct(): float
    {
        $cfg = $this->all();
        return (float)($cfg['risk']['fixed_risk_pct'] ?? 5.0) / 100.0;
    }

    public function atrPeriod(): int
    {
        $cfg = $this->all();
        return (int)($cfg['atr']['period'] ?? 14);
    }

    public function slMult(): float
    {
        $cfg = $this->all();
        return (float)($cfg['atr']['sl_multiplier'] ?? 1.5);
    }

    public function tp1R(): float
    {
        $cfg = $this->all();
        return (float)($cfg['long']['take_profit']['tp1_r'] ?? 2.0);
    }
}
