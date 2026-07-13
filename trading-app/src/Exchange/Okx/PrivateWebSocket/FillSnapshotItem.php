<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

final readonly class FillSnapshotItem
{
    public ?\DateTimeImmutable $occurredAt;

    public function __construct(
        public string $exchange,
        public string $symbol,
        public string $orderId,
        public ?string $clientOrderId,
        public string $tradeId,
        public string $side,
        public string $positionSide,
        public string $size,
        public string $price,
        public ?string $fee,
        public ?string $feeCurrency,
        ?\DateTimeImmutable $occurredAt,
    ) {
        $this->occurredAt = $occurredAt?->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $fill */
    public static function fromProviderArray(array $fill): self
    {
        return new self(
            exchange: self::string($fill, 'exchange'),
            symbol: self::string($fill, 'symbol'),
            orderId: self::string($fill, 'order_id'),
            clientOrderId: self::nullableString($fill, 'client_order_id'),
            tradeId: self::string($fill, 'trade_id'),
            side: self::string($fill, 'side'),
            positionSide: self::string($fill, 'position_side'),
            size: self::string($fill, 'size'),
            price: self::string($fill, 'price'),
            fee: self::nullableString($fill, 'fee'),
            feeCurrency: self::nullableString($fill, 'fee_currency'),
            occurredAt: self::occurredAt($fill['create_time'] ?? null),
        );
    }

    /** @param array<string, mixed> $fill */
    private static function string(array $fill, string $key): string
    {
        return (string) ($fill[$key] ?? '');
    }

    /** @param array<string, mixed> $fill */
    private static function nullableString(array $fill, string $key): ?string
    {
        return isset($fill[$key]) ? (string) $fill[$key] : null;
    }

    private static function occurredAt(mixed $createTime): ?\DateTimeImmutable
    {
        if (!\is_numeric($createTime)) {
            return null;
        }

        $milliseconds = (int) $createTime;
        $occurredAt = \DateTimeImmutable::createFromFormat(
            'U.v',
            sprintf('%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000),
            new \DateTimeZone('UTC'),
        );

        return $occurredAt === false
            ? null
            : $occurredAt->setTimezone(new \DateTimeZone('UTC'));
    }
}
