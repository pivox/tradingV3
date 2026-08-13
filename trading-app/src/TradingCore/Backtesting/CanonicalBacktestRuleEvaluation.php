<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting;

final readonly class CanonicalBacktestRuleEvaluation
{
    /** @param array<string,mixed> $payload */
    public function __construct(private array $payload)
    {
        $expected = [
            'schema_version', 'request_id', 'mode_id', 'mode_version', 'setup_id', 'setup_version',
            'side', 'exchange', 'environment', 'market_type', 'symbol', 'config_hash',
            'condition_catalog_hash', 'snapshot_hash', 'evaluated_at', 'passed', 'reason_code',
            'trace', 'input_hash', 'result_hash',
        ];
        if (array_keys($payload) !== $expected) {
            throw new \InvalidArgumentException('canonical_backtest_rule_result_shape_invalid');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->copy($this->payload);
    }

    private function copy(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $copy = [];
        foreach ($value as $key => $item) {
            $copy[$key] = $this->copy($item);
        }

        return $copy;
    }
}
