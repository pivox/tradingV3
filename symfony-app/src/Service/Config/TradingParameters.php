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

    /**
     * @return array<string,array{attempts:int|null,back_to:?string,wait:mixed}>
     */
    public function orchestrationRetries(): array
    {
        $cfg = $this->all();
        $retries = $cfg['orchestration']['retries'] ?? [];
        if (!is_array($retries)) {
            return [];
        }

        $normalized = [];
        foreach ($retries as $tf => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = strtolower((string)$tf);
            $normalized[$key] = [
                'attempts' => isset($row['attempts']) ? (int)$row['attempts'] : null,
                'back_to'  => isset($row['back_to']) ? strtolower((string)$row['back_to']) : null,
                'wait'     => $row['wait'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @return array{attempts:int|null,back_to:?string,wait:mixed} | null
     */
    public function orchestrationRetryFor(string $timeframe): ?array
    {
        $tf = strtolower($timeframe);
        $retries = $this->orchestrationRetries();
        return $retries[$tf] ?? null;
    }

    public function orchestrationWaitMinutes(string $timeframe): ?int
    {
        $retry = $this->orchestrationRetryFor($timeframe);
        if ($retry === null) {
            return null;
        }

        return $this->parseDurationToMinutes($retry['wait'] ?? null);
    }

    public function parseDurationToMinutes(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)ceil((float)$value);
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        $normalized = strtolower($trimmed);

        if (preg_match('/^(\d+)([smhd])$/i', $normalized, $matches)) {
            $amount = (int)$matches[1];
            $unit = strtolower($matches[2]);
            return match ($unit) {
                's' => (int)ceil($amount / 60),
                'm' => $amount,
                'h' => $amount * 60,
                'd' => $amount * 1440,
                default => null,
            };
        }

        if (preg_match('/^(\d+)\s*(minutes|minute|min)$/i', $trimmed, $matches)) {
            return (int)$matches[1];
        }

        if (preg_match('/^(\d+)\s*(hours|hour|hr|hrs)$/i', $trimmed, $matches)) {
            return (int)$matches[1] * 60;
        }

        if (preg_match('/^pt(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i', $trimmed, $matches)) {
            $hours   = isset($matches[1]) && $matches[1] !== '' ? (int)$matches[1] : 0;
            $minutes = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : 0;
            $seconds = isset($matches[3]) && $matches[3] !== '' ? (int)$matches[3] : 0;
            return max(1, $hours * 60 + $minutes + (int)ceil($seconds / 60));
        }

        return null;
    }
}
