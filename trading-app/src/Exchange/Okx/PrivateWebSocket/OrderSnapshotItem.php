<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Contract\Provider\Dto\OrderDto;

final readonly class OrderSnapshotItem
{
    public \DateTimeImmutable $createdAt;
    public ?\DateTimeImmutable $updatedAt;

    public function __construct(
        public string $orderId,
        public string $symbol,
        public string $side,
        public string $type,
        public string $status,
        public string $quantity,
        public string $filledQuantity,
        public string $remainingQuantity,
        public ?string $price,
        public ?string $stopPrice,
        \DateTimeImmutable $createdAt,
        public ?string $clientOrderId = null,
        public ?string $positionSide = null,
        public bool $reduceOnly = false,
        public bool $postOnly = false,
        public ?string $averagePrice = null,
        ?\DateTimeImmutable $updatedAt = null,
        public ?string $timeInForce = null,
        public ?string $openType = null,
        public ?string $leverage = null,
    ) {
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
        $this->updatedAt = $updatedAt?->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function fromProviderDto(OrderDto $order): self
    {
        $metadata = $order->metadata;
        $clientOrderId = self::knownString($metadata, ['client_order_id', 'clOrdId', 'algoClOrdId']);
        $positionSide = self::knownString($metadata, ['position_side', 'posSide']);
        if ($positionSide !== null) {
            $positionSide = strtolower($positionSide);
            if (!\in_array($positionSide, ['long', 'short'], true)) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
            }
        }
        $providerOrderType = self::knownString($metadata, ['ordType']);
        $type = $order->type->value;
        if ($type === 'stop') {
            $stopLossTrigger = self::knownString($metadata, ['slTriggerPx']);
            $takeProfitTrigger = self::knownString($metadata, ['tpTriggerPx']);
            if ($stopLossTrigger !== null && $takeProfitTrigger !== null) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
            }
            $type = $stopLossTrigger !== null
                ? 'stop_loss'
                : ($takeProfitTrigger !== null ? 'take_profit' : 'stop');
        }

        $postOnly = $providerOrderType === 'post_only';
        $timeInForce = match ($providerOrderType) {
            'limit', 'post_only' => 'gtc',
            'ioc' => 'ioc',
            'fok' => 'fok',
            default => null,
        };

        return new self(
            orderId: $order->orderId,
            symbol: $order->symbol,
            side: $order->side->value,
            type: $type,
            status: $order->status->value,
            quantity: (string) $order->quantity,
            filledQuantity: (string) $order->filledQuantity,
            remainingQuantity: (string) $order->remainingQuantity,
            price: $order->price === null ? null : (string) $order->price,
            stopPrice: $order->stopPrice === null ? null : (string) $order->stopPrice,
            createdAt: $order->createdAt,
            clientOrderId: $clientOrderId,
            positionSide: $positionSide,
            reduceOnly: self::knownBoolean($metadata, 'reduceOnly') ?? false,
            postOnly: $postOnly,
            averagePrice: $order->averagePrice === null ? null : (string) $order->averagePrice,
            updatedAt: $order->updatedAt,
            timeInForce: $timeInForce,
            openType: self::knownString($metadata, ['open_type', 'margin_mode', 'tdMode']),
            leverage: self::knownPositiveNumber($metadata, ['leverage', 'lever']),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @param list<string>        $keys
     */
    private static function knownString(array $metadata, array $keys): ?string
    {
        $result = null;
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $metadata) || $metadata[$key] === null || $metadata[$key] === '') {
                continue;
            }
            if (!\is_scalar($metadata[$key])) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
            }
            $value = (string) $metadata[$key];
            if (trim($value) !== $value || $value === '' || ($result !== null && $result !== $value)) {
                throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
            }
            $result = $value;
        }

        return $result;
    }

    /** @param array<string,mixed> $metadata */
    private static function knownBoolean(array $metadata, string $key): ?bool
    {
        if (!\array_key_exists($key, $metadata) || $metadata[$key] === null || $metadata[$key] === '') {
            return null;
        }

        return match ($metadata[$key]) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid'),
        };
    }

    /**
     * @param array<string,mixed> $metadata
     * @param list<string>        $keys
     */
    private static function knownPositiveNumber(array $metadata, array $keys): ?string
    {
        $value = self::knownString($metadata, $keys);
        if ($value !== null && (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0.0)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }

        return $value;
    }
}
