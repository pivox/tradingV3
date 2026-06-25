<?php

declare(strict_types=1);

namespace App\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Repository\FillCostLedgerEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FillCostLedgerEntryRepository::class)]
#[ORM\Table(name: 'fill_cost_ledger')]
#[ORM\UniqueConstraint(name: 'ux_fill_cost_ledger_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_fill_cost_ledger_internal_trade', columns: ['internal_trade_id', 'occurred_at', 'id'])]
#[ORM\Index(name: 'idx_fill_cost_ledger_internal_position', columns: ['internal_position_id'])]
#[ORM\Index(name: 'idx_fill_cost_ledger_position', columns: ['exchange', 'market_type', 'position_id'])]
#[ORM\Index(name: 'idx_fill_cost_ledger_venue_fill', columns: ['exchange', 'market_type', 'exchange_fill_id'])]
#[ORM\Index(name: 'idx_fill_cost_ledger_venue_order', columns: ['exchange', 'market_type', 'exchange_order_id'])]
#[ORM\Index(name: 'idx_fill_cost_ledger_client_order', columns: ['exchange', 'market_type', 'client_order_id'])]
#[ORM\Index(name: 'idx_fill_cost_ledger_order_intent', columns: ['order_intent_id'])]
final class FillCostLedgerEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 255)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'payload_hash', type: Types::STRING, length: 64)]
    private string $payloadHash;

    #[ORM\Column(name: 'internal_trade_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $internalTradeId = null;

    #[ORM\Column(name: 'internal_position_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $internalPositionId = null;

    #[ORM\Column(name: 'position_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $positionId = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $exchange;

    #[ORM\Column(name: 'market_type', type: Types::STRING, length: 32)]
    private string $marketType;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $side = null;

    #[ORM\Column(name: 'fill_id', type: Types::STRING, length: 128)]
    private string $fillId;

    #[ORM\Column(name: 'exchange_fill_id', type: Types::STRING, length: 128, nullable: true)]
    private ?string $exchangeFillId = null;

    #[ORM\Column(name: 'exchange_order_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $exchangeOrderId = null;

    #[ORM\Column(name: 'client_order_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $clientOrderId = null;

    #[ORM\Column(name: 'order_intent_id', type: Types::BIGINT, nullable: true)]
    private ?int $orderIntentId = null;

    #[ORM\Column(name: 'fill_role', type: Types::STRING, length: 24)]
    private string $fillRole;

    #[ORM\Column(name: 'liquidity_role', type: Types::STRING, length: 24)]
    private string $liquidityRole = 'unknown';

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $quantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $notional = null;

    #[ORM\Column(name: 'fee_amount', type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $feeAmount = null;

    #[ORM\Column(name: 'fee_currency', type: Types::STRING, length: 20, nullable: true)]
    private ?string $feeCurrency = null;

    #[ORM\Column(name: 'fee_usdt', type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $feeUsdt = null;

    #[ORM\Column(name: 'funding_usdt', type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $fundingUsdt = null;

    #[ORM\Column(name: 'spread_cost_usdt', type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $spreadCostUsdt = null;

    #[ORM\Column(name: 'slippage_cost_usdt', type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $slippageCostUsdt = null;

    #[ORM\Column(name: 'borrow_cost_usdt', type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $borrowCostUsdt = null;

    #[ORM\Column(name: 'liquidation_fee_usdt', type: Types::DECIMAL, precision: 30, scale: 12, nullable: true)]
    private ?string $liquidationFeeUsdt = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $source;

    #[ORM\Column(name: 'source_version', type: Types::STRING, length: 64)]
    private string $sourceVersion;

    /** @var list<string> */
    #[ORM\Column(name: 'quality_flags', type: Types::JSON, options: ['jsonb' => true])]
    private array $qualityFlags = [];

    /** @var array<string,mixed> */
    #[ORM\Column(name: 'raw_reference', type: Types::JSON, options: ['jsonb' => true])]
    private array $rawReference = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $idempotencyKey,
        string $payloadHash,
        Exchange|string $exchange,
        MarketType|string $marketType,
        string $symbol,
        string $fillId,
        string $fillRole,
        \DateTimeImmutable $occurredAt,
        string $source,
        string $sourceVersion,
    ) {
        $this->idempotencyKey = self::requireNonBlank($idempotencyKey, 'idempotencyKey');
        $this->payloadHash = self::requireNonBlank($payloadHash, 'payloadHash');
        $this->exchange = $exchange instanceof Exchange ? $exchange->value : strtolower(self::requireNonBlank($exchange, 'exchange'));
        $this->marketType = $marketType instanceof MarketType ? $marketType->value : strtolower(self::requireNonBlank($marketType, 'marketType'));
        $this->symbol = strtoupper(self::requireNonBlank($symbol, 'symbol'));
        $this->fillId = self::requireNonBlank($fillId, 'fillId');
        $this->fillRole = strtolower(self::requireNonBlank($fillRole, 'fillRole'));
        $this->occurredAt = $occurredAt;
        $this->source = self::requireNonBlank($source, 'source');
        $this->sourceVersion = self::requireNonBlank($sourceVersion, 'sourceVersion');
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function getInternalTradeId(): ?string
    {
        return $this->internalTradeId;
    }

    public function setInternalTradeId(?string $internalTradeId): self
    {
        $this->internalTradeId = self::blankToNull($internalTradeId);

        return $this;
    }

    public function getInternalPositionId(): ?string
    {
        return $this->internalPositionId;
    }

    public function setInternalPositionId(?string $internalPositionId): self
    {
        $this->internalPositionId = self::blankToNull($internalPositionId);

        return $this;
    }

    public function getPositionId(): ?string
    {
        return $this->positionId;
    }

    public function setPositionId(?string $positionId): self
    {
        $this->positionId = self::blankToNull($positionId);

        return $this;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function getMarketType(): string
    {
        return $this->marketType;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function setSide(?string $side): self
    {
        $this->side = self::blankToNull($side !== null ? strtoupper($side) : null);

        return $this;
    }

    public function getFillId(): string
    {
        return $this->fillId;
    }

    public function getExchangeFillId(): ?string
    {
        return $this->exchangeFillId;
    }

    public function setExchangeFillId(?string $exchangeFillId): self
    {
        $this->exchangeFillId = self::blankToNull($exchangeFillId);

        return $this;
    }

    public function getExchangeOrderId(): ?string
    {
        return $this->exchangeOrderId;
    }

    public function setExchangeOrderId(?string $exchangeOrderId): self
    {
        $this->exchangeOrderId = self::blankToNull($exchangeOrderId);

        return $this;
    }

    public function getClientOrderId(): ?string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(?string $clientOrderId): self
    {
        $this->clientOrderId = self::blankToNull($clientOrderId);

        return $this;
    }

    public function getOrderIntentId(): ?int
    {
        return $this->orderIntentId;
    }

    public function setOrderIntentId(?int $orderIntentId): self
    {
        $this->orderIntentId = $orderIntentId;

        return $this;
    }

    public function getFillRole(): string
    {
        return $this->fillRole;
    }

    public function getLiquidityRole(): string
    {
        return $this->liquidityRole;
    }

    public function setLiquidityRole(string $liquidityRole): self
    {
        $role = strtolower(trim($liquidityRole));
        $this->liquidityRole = \in_array($role, ['maker', 'taker', 'unknown'], true) ? $role : 'unknown';

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getNotional(): ?string
    {
        return $this->notional;
    }

    public function setNotional(?string $notional): self
    {
        $this->notional = $notional;

        return $this;
    }

    public function getFeeAmount(): ?string
    {
        return $this->feeAmount;
    }

    public function setFeeAmount(?string $feeAmount): self
    {
        $this->feeAmount = $feeAmount;

        return $this;
    }

    public function getFeeCurrency(): ?string
    {
        return $this->feeCurrency;
    }

    public function setFeeCurrency(?string $feeCurrency): self
    {
        $this->feeCurrency = self::blankToNull($feeCurrency !== null ? strtoupper($feeCurrency) : null);

        return $this;
    }

    public function getFeeUsdt(): ?string
    {
        return $this->feeUsdt;
    }

    public function setFeeUsdt(?string $feeUsdt): self
    {
        $this->feeUsdt = $feeUsdt;

        return $this;
    }

    public function getFundingUsdt(): ?string
    {
        return $this->fundingUsdt;
    }

    public function setFundingUsdt(?string $fundingUsdt): self
    {
        $this->fundingUsdt = $fundingUsdt;

        return $this;
    }

    public function getSpreadCostUsdt(): ?string
    {
        return $this->spreadCostUsdt;
    }

    public function setSpreadCostUsdt(?string $spreadCostUsdt): self
    {
        $this->spreadCostUsdt = $spreadCostUsdt;

        return $this;
    }

    public function getSlippageCostUsdt(): ?string
    {
        return $this->slippageCostUsdt;
    }

    public function setSlippageCostUsdt(?string $slippageCostUsdt): self
    {
        $this->slippageCostUsdt = $slippageCostUsdt;

        return $this;
    }

    public function getBorrowCostUsdt(): ?string
    {
        return $this->borrowCostUsdt;
    }

    public function setBorrowCostUsdt(?string $borrowCostUsdt): self
    {
        $this->borrowCostUsdt = $borrowCostUsdt;

        return $this;
    }

    public function getLiquidationFeeUsdt(): ?string
    {
        return $this->liquidationFeeUsdt;
    }

    public function setLiquidationFeeUsdt(?string $liquidationFeeUsdt): self
    {
        $this->liquidationFeeUsdt = $liquidationFeeUsdt;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceVersion(): string
    {
        return $this->sourceVersion;
    }

    /**
     * @return list<string>
     */
    public function getQualityFlags(): array
    {
        return $this->qualityFlags;
    }

    /**
     * @param list<string> $qualityFlags
     */
    public function setQualityFlags(array $qualityFlags): self
    {
        $flags = [];
        foreach ($qualityFlags as $flag) {
            if (\is_string($flag) && trim($flag) !== '') {
                $flags[] = strtolower(trim($flag));
            }
        }

        $this->qualityFlags = array_values(array_unique($flags));

        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function getRawReference(): array
    {
        return $this->rawReference;
    }

    /**
     * @param array<string,mixed> $rawReference
     */
    public function setRawReference(array $rawReference): self
    {
        $this->rawReference = $rawReference;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function requireNonBlank(string $value, string $field): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException(sprintf('%s is required.', $field));
        }

        return $trimmed;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
