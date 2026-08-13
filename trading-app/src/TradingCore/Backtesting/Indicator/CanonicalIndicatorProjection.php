<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;

final readonly class CanonicalIndicatorProjection
{
    /** @var list<string> */
    private const REQUEST_KEYS = [
        'schema_version',
        'request_id',
        'evaluated_at',
        'environment',
        'indicator_engine_version',
        'dataset_binding',
        'symbol',
        'requested_timeframes',
        'candles_by_timeframe',
    ];

    /** @var list<string> */
    private const RESULT_KEYS = [
        'schema_version',
        'request_id',
        'evaluated_at',
        'environment',
        'indicator_engine_version',
        'dataset_binding',
        'symbol',
        'requested_timeframes',
        'snapshots_by_timeframe',
        'input_hash',
        'result_hash',
    ];

    /** @param array<string, mixed> $payload */
    private function __construct(private array $payload)
    {
        if (array_keys($payload) !== self::RESULT_KEYS
            || $payload['schema_version'] !== 'canonical-indicator-projection-result.v1'
            || !$this->isHash($payload['input_hash'])
            || !$this->isHash($payload['result_hash'])
        ) {
            throw new \InvalidArgumentException('canonical_indicator_projection_result_shape_invalid');
        }
    }

    /**
     * @param array<string, mixed> $normalizedRequest
     * @param array<string, array<string, mixed>> $snapshotsByTimeframe
     */
    public static function fromValidatedRequest(
        #[\SensitiveParameter] array $normalizedRequest,
        array $snapshotsByTimeframe,
    ): self {
        if (array_keys($normalizedRequest) !== self::REQUEST_KEYS
            || !\is_array($normalizedRequest['requested_timeframes'])
            || !array_is_list($normalizedRequest['requested_timeframes'])
            || array_keys($snapshotsByTimeframe) !== $normalizedRequest['requested_timeframes']
        ) {
            throw new \InvalidArgumentException('canonical_indicator_projection_result_shape_invalid');
        }

        $inputHash = CanonicalBacktestRuleEvaluator::canonicalHash($normalizedRequest);
        $result = [
            'schema_version' => 'canonical-indicator-projection-result.v1',
            'request_id' => $normalizedRequest['request_id'],
            'evaluated_at' => $normalizedRequest['evaluated_at'],
            'environment' => $normalizedRequest['environment'],
            'indicator_engine_version' => $normalizedRequest['indicator_engine_version'],
            'dataset_binding' => $normalizedRequest['dataset_binding'],
            'symbol' => $normalizedRequest['symbol'],
            'requested_timeframes' => $normalizedRequest['requested_timeframes'],
            'snapshots_by_timeframe' => $snapshotsByTimeframe,
            'input_hash' => $inputHash,
        ];
        $result['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($result);

        /** @var array<string, mixed> $defensiveResult */
        $defensiveResult = self::copy($result);

        return new self($defensiveResult);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        /** @var array<string, mixed> $copy */
        $copy = self::copy($this->payload);

        return $copy;
    }

    private function isHash(mixed $value): bool
    {
        return \is_string($value) && preg_match('/\Asha256:[0-9a-f]{64}\z/D', $value) === 1;
    }

    private static function copy(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $copy = [];
        foreach ($value as $key => $item) {
            $copy[$key] = self::copy($item);
        }

        return $copy;
    }
}
