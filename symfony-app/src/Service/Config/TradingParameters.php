<?php

namespace App\Service\Config;

use Symfony\Component\Yaml\Yaml;

class TradingParameters
{
    public function __construct(private string $projectDir) {}

    public function getConfig(): array
    {
        $path = $this->projectDir . '/config/packages/trading.yaml';
        $all  = Yaml::parseFile($path);

        // Adapte selon la clé que tu utilises (ici "parameters.app.trading")
        return $all['parameters']['app.trading'] ?? [];
    }
}
