<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;

final class HyperliquidTestnetOrderPlanFileDecoder
{
    private const MAX_FILE_BYTES = 65_536;

    /** @var list<string> */
    private const PLAN_FIELDS = [
        'symbol',
        'profile',
        'config_hash',
        'exchange',
        'market_type',
        'side',
        'order_type',
        'margin_mode',
        'time_in_force',
        'entry_price',
        'quantity',
        'leverage',
        'client_order_id',
        'idempotency_key',
        'protection_plan',
    ];

    public function decode(string $path): OrderPlan
    {
        $contents = $this->readRegularFile($path);
        try {
            $envelope = json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('order_plan_json_invalid');
        }
        if (!is_array($envelope) || array_is_list($envelope)) {
            throw new \InvalidArgumentException('order_plan_envelope_invalid');
        }

        $this->assertExactKeys($envelope, ['schema_version', 'order_plan']);
        if (($envelope['schema_version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('order_plan_schema_version_invalid');
        }
        $plan = $this->object($envelope, 'order_plan');
        $this->assertExactKeys($plan, self::PLAN_FIELDS);

        $exchange = $this->exactString($plan, 'exchange', 'hyperliquid');
        $marketType = $this->exactString($plan, 'market_type', 'perpetual');
        $orderType = $this->exactString($plan, 'order_type', 'limit');
        $timeInForce = $this->exactString($plan, 'time_in_force', 'gtc');
        $side = $this->enumString($plan, 'side', ['long', 'short']);
        $marginMode = $this->enumString($plan, 'margin_mode', ['isolated', 'cross']);
        $entryPrice = $this->positiveNumber($plan, 'entry_price', 1_000_000_000_000.0);
        $quantity = $this->positiveNumber($plan, 'quantity', 1_000_000_000_000.0);
        $leverage = $this->boundedInt($plan, 'leverage', 1, 100);

        $symbol = $this->patternString($plan, 'symbol', '/^[A-Z0-9][A-Z0-9_-]{1,31}$/D');
        $profile = $this->patternString($plan, 'profile', '/^[a-z0-9][a-z0-9_.-]{0,63}$/D');
        $configHash = $this->patternString($plan, 'config_hash', '/^[a-f0-9]{64}$/D');
        $clientOrderId = $this->opaqueString($plan, 'client_order_id');
        $idempotencyKey = $this->opaqueString($plan, 'idempotency_key');

        $protection = $this->object($plan, 'protection_plan');
        $this->assertExactKeys($protection, ['stop_loss', 'liquidation_check']);
        $stop = $this->object($protection, 'stop_loss');
        $this->assertExactKeys($stop, ['stop_price', 'stop_pct', 'stop_distance', 'stop_source', 'is_full_size']);
        if (($stop['is_full_size'] ?? null) !== true) {
            throw new \InvalidArgumentException('order_plan_full_size_stop_required');
        }
        $stopLoss = new StopLossResult(
            stopPrice: $this->positiveNumber($stop, 'stop_price', 1_000_000_000_000.0),
            stopPct: $this->positiveNumber($stop, 'stop_pct', 1.0),
            stopDistance: $this->positiveNumber($stop, 'stop_distance', 1_000_000_000_000.0),
            stopSource: $this->patternString($stop, 'stop_source', '/^[a-z][a-z0-9_.-]{0,31}$/D'),
            isFullSize: true,
        );

        $liquidation = $this->object($protection, 'liquidation_check');
        $this->assertExactKeys($liquidation, [
            'is_safe',
            'liquidation_price',
            'liquidation_distance_pct',
            'stop_to_liquidation_ratio',
        ]);
        if (($liquidation['is_safe'] ?? null) !== true) {
            throw new \InvalidArgumentException('order_plan_liquidation_guard_required');
        }
        $liquidationCheck = new LiquidationCheckResult(
            isSafe: true,
            liquidationPrice: $this->positiveNumber($liquidation, 'liquidation_price', 1_000_000_000_000.0),
            liquidationDistancePct: $this->positiveNumber($liquidation, 'liquidation_distance_pct', 1.0),
            stopToLiquidationRatio: $this->positiveNumber($liquidation, 'stop_to_liquidation_ratio', 1_000_000.0),
        );

        return new OrderPlan(
            symbol: $symbol,
            profile: $profile,
            exchange: $exchange,
            marketType: $marketType,
            side: $side,
            orderType: $orderType,
            marginMode: $marginMode,
            timeInForce: $timeInForce,
            entryPrice: $entryPrice,
            quantity: $quantity,
            leverage: $leverage,
            protectionPlan: new ProtectionPlan(
                stopLoss: $stopLoss,
                takeProfit: null,
                liquidationCheck: $liquidationCheck,
                isValid: true,
                status: ProtectionPlanStatus::Valid,
            ),
            clientOrderId: $clientOrderId,
            idempotencyKey: $idempotencyKey,
            configHash: $configHash,
        );
    }

    private function readRegularFile(string $path): string
    {
        if ($path === '' || is_link($path)) {
            throw new \InvalidArgumentException('order_plan_file_invalid');
        }
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('order_plan_file_invalid');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('order_plan_file_invalid');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened)
                || ($opened['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $before['dev']
                || $opened['ino'] !== $before['ino']
                || $opened['size'] > self::MAX_FILE_BYTES
            ) {
                throw new \InvalidArgumentException('order_plan_file_invalid');
            }
            $contents = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            if (!is_string($contents) || strlen($contents) > self::MAX_FILE_BYTES) {
                throw new \InvalidArgumentException('order_plan_file_invalid');
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string>        $expected
     */
    private function assertExactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('order_plan_fields_invalid');
        }
    }

    /**
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed>
     */
    private function object(array $source, string $field): array
    {
        $value = $source[$field] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('order_plan_object_invalid');
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function exactString(array $source, string $field, string $expected): string
    {
        $value = $source[$field] ?? null;
        if ($value !== $expected) {
            throw new \InvalidArgumentException('order_plan_enum_invalid');
        }

        return $expected;
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string>        $allowed
     */
    private function enumString(array $source, string $field, array $allowed): string
    {
        $value = $source[$field] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('order_plan_enum_invalid');
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function patternString(array $source, string $field, string $pattern): string
    {
        $value = $source[$field] ?? null;
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException('order_plan_string_invalid');
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function opaqueString(array $source, string $field): string
    {
        return $this->patternString($source, $field, '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D');
    }

    /** @param array<string,mixed> $source */
    private function positiveNumber(array $source, string $field, float $maximum): float
    {
        $value = $source[$field] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0 || $value > $maximum) {
            throw new \InvalidArgumentException('order_plan_number_invalid');
        }

        return (float) $value;
    }

    /** @param array<string,mixed> $source */
    private function boundedInt(array $source, string $field, int $minimum, int $maximum): int
    {
        $value = $source[$field] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException('order_plan_integer_invalid');
        }

        return $value;
    }
}
