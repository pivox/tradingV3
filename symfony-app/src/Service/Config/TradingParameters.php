<?php

namespace App\Service\Config;

use Symfony\Component\Yaml\Yaml;

class TradingParameters
{
    public function __construct(private string $configFile) {}

    public function getConfig(): array
    {
        return Yaml::parseFile($this->configFile);
    }

    public function riskPct(): float { return (float)($this->cfg['risk']['fixed_risk_pct'] ?? 5.0) / 100.0; }
    public function atrPeriod(): int { return (int)($this->cfg['atr']['period'] ?? 14); }
    public function slMult(): float { return (float)($this->cfg['atr']['sl_multiplier'] ?? 1.5); }
    public function tp1R(): float { return (float)($this->cfg['long']['take_profit']['tp1_r'] ?? 2.0); }

}
