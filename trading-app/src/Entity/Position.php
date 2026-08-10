<?php

declare(strict_types=1);

namespace App\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Repository\PositionRepository;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\Trading\Lineage\Persistence\CanonicalLineageProjection;
use App\Trading\Lineage\Persistence\CanonicalPositionPredecessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'positions')]
#[ORM\Index(name: 'idx_positions_symbol', columns: ['exchange', 'market_type', 'symbol'])]
class Position
{
    use CanonicalLineageProjection {
        requireLineageContext as private requireProjectedLineageContext;
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'bitmart'])]
    private string $exchange = 'bitmart';

    #[ORM\Column(name: 'market_type', type: Types::STRING, length: 32, options: ['default' => 'perpetual'])]
    private string $marketType = 'perpetual';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $side; // LONG | SHORT

    #[ORM\Column(name: 'canonical_exchange_position_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $canonicalExchangePositionId = null;

    #[ORM\ManyToOne(targetEntity: FuturesOrder::class)]
    #[ORM\JoinColumn(name: 'opening_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?FuturesOrder $openingOrder = null;

    #[ORM\ManyToOne(targetEntity: FuturesOrderTrade::class)]
    #[ORM\JoinColumn(name: 'opening_fill_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?FuturesOrderTrade $openingFill = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(name: 'avg_entry_price', type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $avgEntryPrice = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $leverage = null;

    #[ORM\Column(name: 'unrealized_pnl', type: Types::DECIMAL, precision: 28, scale: 12, nullable: true)]
    private ?string $unrealizedPnl = null;

    #[ORM\Column(type: Types::STRING, length: 16, options: ['default' => 'OPEN'])]
    private string $status = 'OPEN'; // OPEN | CLOSED

    /** @var array<string,mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $insertedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $symbol,
        string $side,
        Exchange|string $exchange = Exchange::BITMART,
        MarketType|string $marketType = MarketType::PERPETUAL,
    )
    {
        $this->setExchange($exchange);
        $this->setMarketType($marketType);
        $this->symbol = strtoupper($symbol);
        $this->side = strtoupper($side);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->insertedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function setExchange(Exchange|string $exchange): self
    {
        $this->exchange = $exchange instanceof Exchange ? $exchange->value : strtolower($exchange);
        return $this;
    }

    public function getMarketType(): string
    {
        return $this->marketType;
    }

    public function setMarketType(MarketType|string $marketType): self
    {
        $this->marketType = $marketType instanceof MarketType ? $marketType->value : strtolower($marketType);
        return $this;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function applyCanonicalPredecessor(CanonicalPositionPredecessor $predecessor): self
    {
        if (!\in_array($predecessor->order->getSide(), [1, 4], true)) {
            throw new LineageContextException('canonical_identity_invalid:position_opening_order');
        }
        $source = $predecessor->order->requireLineageContext();
        if ($predecessor->context->toArray() !== $source->toArray()) {
            throw new LineageContextException('canonical_identity_mismatch:position_order_predecessor');
        }
        if ($predecessor->fill !== null) {
            if ($predecessor->fill->getSide() !== $predecessor->order->getSide()
                || $predecessor->fill->requireLineageContext()->toArray() !== $source->toArray()
            ) {
                throw new LineageContextException('canonical_identity_mismatch:position_fill_predecessor');
            }
        }
        $source->assertTradeBoundary($this->symbol, $this->side, $this->exchange, $this->marketType);
        if ($this->canonicalExchangePositionId !== null
            && $this->canonicalExchangePositionId !== $predecessor->exchangePositionId
        ) {
            throw new LineageContextException('canonical_identity_mismatch:exchange_position_id');
        }
        if ($this->openingOrder !== null && $this->openingOrder !== $predecessor->order) {
            throw new LineageContextException('canonical_identity_mismatch:position_order_predecessor');
        }
        if ($this->openingFill !== null && $predecessor->fill !== null && $this->openingFill !== $predecessor->fill) {
            throw new LineageContextException('canonical_identity_mismatch:position_fill_predecessor');
        }

        $this->projectCanonicalLineage($source, 'position');
        $this->canonicalExchangePositionId = $predecessor->exchangePositionId;
        $this->openingOrder = $predecessor->order;
        $this->openingFill ??= $predecessor->fill;

        return $this->touch();
    }

    public function requireLineageContext(): LineageContext
    {
        $context = $this->requireProjectedLineageContext();
        $context->assertTradeBoundary($this->symbol, $this->side, $this->exchange, $this->marketType);
        if ($this->canonicalExchangePositionId === null || trim($this->canonicalExchangePositionId) === '') {
            throw new LineageContextException('canonical_identity_missing:exchange_position_id');
        }
        if (!$this->openingOrder instanceof FuturesOrder) {
            throw new LineageContextException('canonical_identity_missing:position_order_predecessor');
        }
        if ($this->openingOrder->requireLineageContext()->toArray() !== $context->toArray()) {
            throw new LineageContextException('canonical_identity_mismatch:position_order_predecessor');
        }
        if ($this->openingFill instanceof FuturesOrderTrade
            && $this->openingFill->requireLineageContext()->toArray() !== $context->toArray()
        ) {
            throw new LineageContextException('canonical_identity_mismatch:position_fill_predecessor');
        }

        return $context;
    }

    public function getCanonicalExchangePositionId(): ?string { return $this->canonicalExchangePositionId; }
    public function getOpeningOrder(): ?FuturesOrder { return $this->openingOrder; }
    public function getOpeningFill(): ?FuturesOrderTrade { return $this->openingFill; }

    protected function hasAdditionalProjectedCanonicalField(): bool
    {
        return $this->canonicalExchangePositionId !== null
            || $this->openingOrder !== null
            || $this->openingFill !== null;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): self
    {
        $this->size = $size;
        return $this->touch();
    }

    public function getAvgEntryPrice(): ?string
    {
        return $this->avgEntryPrice;
    }

    public function setAvgEntryPrice(?string $avgEntryPrice): self
    {
        $this->avgEntryPrice = $avgEntryPrice;
        return $this->touch();
    }

    public function getLeverage(): ?int
    {
        return $this->leverage;
    }

    public function setLeverage(?int $leverage): self
    {
        $this->leverage = $leverage;
        return $this->touch();
    }

    public function getUnrealizedPnl(): ?string
    {
        return $this->unrealizedPnl;
    }

    public function setUnrealizedPnl(?string $unrealizedPnl): self
    {
        $this->unrealizedPnl = $unrealizedPnl;
        return $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = strtoupper($status);
        return $this->touch();
    }

    /** @return array<string,mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string,mixed> $payload */
    public function mergePayload(array $payload): self
    {
        $this->payload = array_replace($this->payload, $payload);
        return $this->touch();
    }

    public function getInsertedAt(): \DateTimeImmutable
    {
        return $this->insertedAt;
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
