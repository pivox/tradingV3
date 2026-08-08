<?php

declare(strict_types=1);

namespace App\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Repository\FuturesOrderTradeRepository;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\Trading\Lineage\Persistence\CanonicalLineageProjection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FuturesOrderTradeRepository::class)]
#[ORM\Table(name: 'futures_order_trade')]
#[ORM\UniqueConstraint(name: 'ux_futures_order_trade_exchange_market_trade_id', columns: ['exchange', 'market_type', 'trade_id'])]
#[ORM\Index(name: 'idx_futures_order_trade_order_id', columns: ['order_id'])]
#[ORM\Index(name: 'idx_futures_order_trade_symbol', columns: ['exchange', 'market_type', 'symbol'])]
class FuturesOrderTrade
{
    use CanonicalLineageProjection {
        requireLineageContext as private requireProjectedLineageContext;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $tradeId = null;

    #[ORM\Column(name: 'canonical_exchange_fill_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $canonicalExchangeFillId = null;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $orderId; // référence vers futures_order.order_id

    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'bitmart'])]
    private string $exchange = 'bitmart';

    #[ORM\Column(name: 'market_type', type: Types::STRING, length: 32, options: ['default' => 'perpetual'])]
    private string $marketType = 'perpetual';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::INTEGER)]
    private int $side;

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12)]
    private string $price;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(name: 'quantity_decimal', type: Types::DECIMAL, precision: 36, scale: 18, nullable: true)]
    private ?string $quantityDecimal = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $fee = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $feeCurrency = null;

    #[ORM\Column(type: Types::BIGINT)]
    private int $tradeTime; // timestamp millis

    /** @var array<string,mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $rawData = [];

    #[ORM\ManyToOne(targetEntity: FuturesOrder::class)]
    #[ORM\JoinColumn(name: 'futures_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?FuturesOrder $futuresOrder = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTradeId(): ?string
    {
        return $this->tradeId;
    }

    public function setTradeId(?string $tradeId): self
    {
        $this->tradeId = $tradeId;
        return $this->touch();
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): self
    {
        $this->orderId = $orderId;
        return $this->touch();
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function setExchange(Exchange|string $exchange): self
    {
        $this->exchange = $exchange instanceof Exchange ? $exchange->value : strtolower($exchange);
        return $this->touch();
    }

    public function getMarketType(): string
    {
        return $this->marketType;
    }

    public function setMarketType(MarketType|string $marketType): self
    {
        $this->marketType = $marketType instanceof MarketType ? $marketType->value : strtolower($marketType);
        return $this->touch();
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): self
    {
        $this->symbol = strtoupper($symbol);
        return $this->touch();
    }

    public function getSide(): int
    {
        return $this->side;
    }

    public function setSide(int $side): self
    {
        $this->side = $side;
        return $this->touch();
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this->touch();
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;
        return $this->touch();
    }

    public function getQuantityDecimal(): ?string
    {
        return $this->quantityDecimal;
    }

    public function setQuantityDecimal(?string $quantityDecimal): self
    {
        $this->quantityDecimal = $quantityDecimal;
        return $this->touch();
    }

    public function getFee(): ?string
    {
        return $this->fee;
    }

    public function setFee(?string $fee): self
    {
        $this->fee = $fee;
        return $this->touch();
    }

    public function getFeeCurrency(): ?string
    {
        return $this->feeCurrency;
    }

    public function setFeeCurrency(?string $feeCurrency): self
    {
        $this->feeCurrency = $feeCurrency;
        return $this->touch();
    }

    public function getTradeTime(): int
    {
        return $this->tradeTime;
    }

    public function setTradeTime(int $tradeTime): self
    {
        $this->tradeTime = $tradeTime;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * @param array<string,mixed> $rawData
     */
    public function setRawData(array $rawData): self
    {
        $this->rawData = $rawData;
        return $this->touch();
    }

    public function getFuturesOrder(): ?FuturesOrder
    {
        return $this->futuresOrder;
    }

    public function setFuturesOrder(?FuturesOrder $futuresOrder): self
    {
        $this->futuresOrder = $futuresOrder;
        return $this->touch();
    }

    public function applyFuturesOrderLineage(FuturesOrder $order): self
    {
        $source = $order->requireLineageContext();
        if (!isset($this->orderId) || trim($this->orderId) === '' || $this->orderId !== $order->getOrderId()) {
            throw new LineageContextException('canonical_identity_mismatch:exchange_order_id');
        }
        if ($this->tradeId === null || trim($this->tradeId) === '') {
            throw new LineageContextException('canonical_identity_missing:exchange_trade_id');
        }
        if ($this->canonicalExchangeFillId !== null && $this->canonicalExchangeFillId !== $this->tradeId) {
            throw new LineageContextException('canonical_identity_mismatch:exchange_trade_id');
        }
        if ($this->side !== $order->getSide()) {
            throw new LineageContextException('canonical_identity_mismatch:fill_order_side');
        }
        $source->assertTradeBoundary(
            $this->symbol,
            self::canonicalSide($this->side),
            $this->exchange,
            $this->marketType,
        );

        $this->projectCanonicalLineage($source, 'futures_order_trade');
        $this->canonicalExchangeFillId = $this->tradeId;
        $this->futuresOrder = $order;

        return $this->touch();
    }

    public function requireLineageContext(): LineageContext
    {
        $context = $this->requireProjectedLineageContext();
        if (!$this->futuresOrder instanceof FuturesOrder) {
            throw new LineageContextException('canonical_identity_missing:futures_order_predecessor');
        }
        $context->assertTradeBoundary(
            $this->symbol,
            self::canonicalSide($this->side),
            $this->exchange,
            $this->marketType,
        );
        if (!isset($this->orderId) || $context->orderId !== $this->orderId) {
            throw new LineageContextException('canonical_identity_mismatch:exchange_order_id');
        }
        if ($this->tradeId === null || $this->canonicalExchangeFillId !== $this->tradeId) {
            throw new LineageContextException('canonical_identity_mismatch:exchange_trade_id');
        }
        if ($this->side !== $this->futuresOrder->getSide()) {
            throw new LineageContextException('canonical_identity_mismatch:fill_order_side');
        }
        $source = $this->futuresOrder->requireLineageContext();
        if ($source->toArray() !== $context->toArray()) {
            throw new LineageContextException('canonical_identity_mismatch:futures_order_predecessor');
        }

        return $context;
    }

    public function getCanonicalExchangeFillId(): ?string
    {
        return $this->canonicalExchangeFillId;
    }

    protected function hasAdditionalProjectedCanonicalField(): bool
    {
        return $this->canonicalExchangeFillId !== null;
    }

    private static function canonicalSide(int $side): string
    {
        return match ($side) {
            1, 2 => 'LONG',
            3, 4 => 'SHORT',
            default => throw new LineageContextException('canonical_identity_invalid:side'),
        };
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }
}
