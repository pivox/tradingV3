<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\SlTp\Dto\LiquidationCheckRequest;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use App\TradingCore\SlTp\Service\LiquidationGuard;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Schema v1 accepts only authoritative order primitives. Prices and quantity are
 * canonical decimal strings (scale <= 8); stop and liquidation metrics are derived.
 * Without exchange maintenance-margin evidence, LiquidationGuard uses the existing
 * fallback maintenance margin 0.0 and a conservative minimum distance ratio of 3.0.
 */
final class HyperliquidTestnetOrderPlanFileDecoder
{
    private const MAX_DECIMAL_SCALE = 8;
    private const MAX_DECIMAL_VALUE = '1000000000000';

    // Existing LiquidationGuard fallback: derive from entry/leverage with no maintenance-margin adjustment.
    private const DEFAULT_MAINTENANCE_MARGIN_RATE = 0.0;
    private const MIN_LIQUIDATION_DISTANCE_RATIO = 3.0;

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

    private readonly LiquidationGuard $liquidationGuard;
    private readonly BoundedDuplicateAwareJsonDecoder $jsonDecoder;
    private readonly HyperliquidTestnetOrderPlanFileReaderInterface $fileReader;

    public function __construct(
        ?LiquidationGuard $liquidationGuard = null,
        ?BoundedDuplicateAwareJsonDecoder $jsonDecoder = null,
        ?HyperliquidTestnetOrderPlanFileReaderInterface $fileReader = null,
    ) {
        $this->liquidationGuard = $liquidationGuard ?? new LiquidationGuard();
        $this->jsonDecoder = $jsonDecoder ?? new BoundedDuplicateAwareJsonDecoder();
        $this->fileReader = $fileReader ?? new HyperliquidTestnetOrderPlanFileReader();
    }

    public function decode(string $path): OrderPlan
    {
        $contents = $this->fileReader->read($path);
        $envelope = $this->jsonDecoder->decode($contents);
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
        $entryDecimal = $this->positiveDecimal($plan, 'entry_price');
        $quantityDecimal = $this->positiveDecimal($plan, 'quantity');
        $entryPrice = $this->exactFloat($entryDecimal, self::MAX_DECIMAL_SCALE);
        $quantity = $this->exactFloat($quantityDecimal, self::MAX_DECIMAL_SCALE);
        $leverage = $this->boundedInt($plan, 'leverage', 1, 100);

        $symbol = $this->patternString($plan, 'symbol', '/^[A-Z0-9][A-Z0-9_-]{1,31}$/D');
        $profile = $this->patternString($plan, 'profile', '/^[a-z0-9][a-z0-9_.-]{0,63}$/D');
        $configHash = $this->patternString($plan, 'config_hash', '/^[a-f0-9]{64}$/D');
        $clientOrderId = $this->opaqueString($plan, 'client_order_id');
        $idempotencyKey = $this->opaqueString($plan, 'idempotency_key');

        $protection = $this->object($plan, 'protection_plan');
        $this->assertExactKeys($protection, ['stop_loss']);
        $stop = $this->object($protection, 'stop_loss');
        $this->assertExactKeys($stop, ['stop_price', 'stop_source', 'is_full_size']);
        if (($stop['is_full_size'] ?? null) !== true) {
            throw new \InvalidArgumentException('order_plan_full_size_stop_required');
        }
        $stopDecimal = $this->positiveDecimal($stop, 'stop_price');
        $stopDistance = $entryDecimal->minus($stopDecimal)->abs();
        if ($stopDistance->isZero()) {
            throw new \InvalidArgumentException('order_plan_stop_distance_invalid');
        }
        $stopPrice = $this->exactFloat($stopDecimal, self::MAX_DECIMAL_SCALE);
        $stopLoss = new StopLossResult(
            stopPrice: $stopPrice,
            stopPct: $this->exactFloat(
                $stopDistance->dividedBy($entryDecimal, 12, RoundingMode::HALF_UP),
                12,
            ),
            stopDistance: $this->exactFloat($stopDistance, self::MAX_DECIMAL_SCALE),
            stopSource: $this->patternString($stop, 'stop_source', '/^[a-z][a-z0-9_.-]{0,31}$/D'),
            isFullSize: true,
        );

        $liquidationCheck = $this->liquidationGuard->check(new LiquidationCheckRequest(
            symbol: $symbol,
            instrument: $symbol,
            exchange: $exchange,
            marketType: $marketType,
            direction: $side,
            entryPrice: $entryPrice,
            stopPrice: $stopPrice,
            leverage: $leverage,
            maintenanceMarginRate: self::DEFAULT_MAINTENANCE_MARGIN_RATE,
            liquidationPrice: null,
            minDistanceRatio: self::MIN_LIQUIDATION_DISTANCE_RATIO,
            metadata: ['model' => 'entry_leverage_default_maintenance_margin'],
        ));
        if (!$liquidationCheck->isSafe) {
            throw new \InvalidArgumentException('order_plan_liquidation_guard_unsafe');
        }

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
    private function positiveDecimal(array $source, string $field): BigDecimal
    {
        $value = $source[$field] ?? null;
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('order_plan_number_invalid');
        }

        try {
            $decimal = BigDecimal::of($value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('order_plan_number_invalid');
        }
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())
            || $decimal->isGreaterThan(BigDecimal::of(self::MAX_DECIMAL_VALUE))
            || $decimal->getScale() > self::MAX_DECIMAL_SCALE
        ) {
            throw new \InvalidArgumentException('order_plan_number_invalid');
        }

        return $decimal;
    }

    private function exactFloat(BigDecimal $decimal, int $scale): float
    {
        $float = $decimal->toFloat();
        if (!is_finite($float)) {
            throw new \InvalidArgumentException('order_plan_float_invalid');
        }
        $roundTrip = BigDecimal::of(sprintf('%.' . $scale . 'F', $float));
        if (!$roundTrip->isEqualTo($decimal)) {
            throw new \InvalidArgumentException('order_plan_float_not_exactly_representable');
        }

        return $float;
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
