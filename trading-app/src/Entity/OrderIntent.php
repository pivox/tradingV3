<?php

declare(strict_types=1);

namespace App\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Repository\OrderIntentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderIntentRepository::class)]
#[ORM\Table(name: 'order_intent')]
#[ORM\UniqueConstraint(name: 'ux_order_intent_exchange_market_client_order_id', columns: ['exchange', 'market_type', 'client_order_id'])]
#[ORM\UniqueConstraint(name: 'ux_order_intent_exchange_market_active_decision_key', columns: ['exchange', 'market_type', 'decision_key'], options: ['where' => "decision_key IS NOT NULL AND status IN ('DRAFT','VALIDATED','READY_TO_SEND','SENT')"])]
#[ORM\Index(name: 'idx_order_intent_symbol', columns: ['exchange', 'market_type', 'symbol'])]
#[ORM\Index(name: 'idx_order_intent_status', columns: ['status'])]
#[ORM\Index(name: 'idx_order_intent_client_order_id', columns: ['client_order_id'])]
#[ORM\Index(name: 'idx_order_intent_exchange_market_decision_key', columns: ['exchange', 'market_type', 'decision_key'])]
class OrderIntent
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_READY_TO_SEND = 'READY_TO_SEND';
    public const STATUS_SENT = 'SENT';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const TYPE_LIMIT = 'limit';
    public const TYPE_MARKET = 'market';

    public const OPEN_TYPE_ISOLATED = 'isolated';
    public const OPEN_TYPE_CROSS = 'cross';

    public const POSITION_MODE_ONE_WAY = 'one_way';
    public const POSITION_MODE_HEDGE = 'hedge';

    public const PRESET_MODE_NONE = 'none';
    public const PRESET_MODE_PRESET_ON_ENTRY = 'preset_on_entry';
    public const PRESET_MODE_POSITION_TP_SL = 'position_tp_sl';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'bitmart'])]
    private string $exchange = 'bitmart';

    #[ORM\Column(name: 'market_type', type: Types::STRING, length: 32, options: ['default' => 'perpetual'])]
    private string $marketType = 'perpetual';

    #[ORM\Column(name: 'decision_key', type: Types::STRING, length: 255, nullable: true)]
    private ?string $decisionKey = null;

    #[ORM\Column(name: 'strategy_profile', type: Types::STRING, length: 80, nullable: true)]
    private ?string $strategyProfile = null;

    #[ORM\Column(name: 'strategy_version', type: Types::STRING, length: 80, nullable: true)]
    private ?string $strategyVersion = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $timeframe = null;

    #[ORM\Column(name: 'candle_open_ts', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $candleOpenTs = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $side; // 1=open_long, 2=close_long, 3=close_short, 4=open_short

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type; // limit, market

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $openType; // isolated, cross

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $leverage = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $positionMode; // one_way, hedge

    #[ORM\Column(type: Types::DECIMAL, precision: 24, scale: 12, nullable: true)]
    private ?string $price = null; // Prix limit (pour type=limit)

    #[ORM\Column(type: Types::INTEGER)]
    private int $size; // Nombre de contrats

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $clientOrderId; // Généré unique

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $presetMode; // none, preset_on_entry, position_tp_sl

    /** @var array<string,mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $quantization = []; // tick_size, step_size, min_notional, price_precision, vol_precision, etc.

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $status = self::STATUS_DRAFT;

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    private ?array $rawInputs = null; // Données brutes avant normalisation

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true], nullable: true)]
    private ?array $validationErrors = null; // Erreurs de validation

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $orderId = null; // Order ID de l'exchange après envoi

    #[ORM\Column(name: 'exchange_order_id', type: Types::STRING, length: 80, nullable: true)]
    private ?string $exchangeOrderId = null;

    #[ORM\Column(name: 'internal_trade_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $internalTradeId = null;

    #[ORM\Column(name: 'internal_position_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $internalPositionId = null;

    #[ORM\Column(name: 'correlation_run_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $correlationRunId = null;

    #[ORM\Column(name: 'orchestration_run_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $orchestrationRunId = null;

    #[ORM\Column(name: 'orchestration_set_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $orchestrationSetId = null;

    #[ORM\Column(name: 'orchestration_dashboard_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $orchestrationDashboardId = null;

    #[ORM\Column(name: 'origin', type: Types::STRING, length: 24, options: ['default' => 'legacy'])]
    private string $origin = 'legacy';

    #[ORM\Column(name: 'replay_of_run_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $replayOfRunId = null;

    #[ORM\Column(name: 'replay_of_correlation_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $replayOfCorrelationId = null;

    #[ORM\Column(name: 'attempt_number', type: Types::INTEGER, options: ['default' => 1])]
    private int $attemptNumber = 1;

    #[ORM\Column(name: 'config_hash', type: Types::STRING, length: 128, nullable: true)]
    private ?string $configHash = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $failureReason = null; // Raison de l'échec

    /** @var Collection<int, OrderProtection> */
    #[ORM\OneToMany(targetEntity: OrderProtection::class, mappedBy: 'orderIntent', cascade: ['persist', 'remove'])]
    private Collection $protections;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    public function __construct()
    {
        $this->protections = new ArrayCollection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
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

    public function getDecisionKey(): ?string
    {
        return $this->decisionKey;
    }

    public function setDecisionKey(?string $decisionKey): self
    {
        $decisionKey = $decisionKey !== null ? trim($decisionKey) : null;
        $this->decisionKey = $decisionKey !== '' ? $decisionKey : null;
        return $this->touch();
    }

    public function getStrategyProfile(): ?string
    {
        return $this->strategyProfile;
    }

    public function setStrategyProfile(?string $strategyProfile): self
    {
        $strategyProfile = $strategyProfile !== null ? trim($strategyProfile) : null;
        $this->strategyProfile = $strategyProfile !== '' ? $strategyProfile : null;
        return $this->touch();
    }

    public function getStrategyVersion(): ?string
    {
        return $this->strategyVersion;
    }

    public function setStrategyVersion(?string $strategyVersion): self
    {
        $strategyVersion = $strategyVersion !== null ? trim($strategyVersion) : null;
        $this->strategyVersion = $strategyVersion !== '' ? $strategyVersion : null;
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

    public function getTimeframe(): ?string
    {
        return $this->timeframe;
    }

    public function setTimeframe(?string $timeframe): self
    {
        $timeframe = $timeframe !== null ? strtolower(trim($timeframe)) : null;
        $this->timeframe = $timeframe !== '' ? $timeframe : null;
        return $this->touch();
    }

    public function getCandleOpenTs(): ?\DateTimeImmutable
    {
        return $this->candleOpenTs;
    }

    public function setCandleOpenTs(?\DateTimeImmutable $candleOpenTs): self
    {
        $this->candleOpenTs = $candleOpenTs;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = strtolower($type);
        return $this->touch();
    }

    public function getOpenType(): string
    {
        return $this->openType;
    }

    public function setOpenType(string $openType): self
    {
        $this->openType = strtolower($openType);
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

    public function getPositionMode(): string
    {
        return $this->positionMode;
    }

    public function setPositionMode(string $positionMode): self
    {
        $this->positionMode = strtolower($positionMode);
        return $this->touch();
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): self
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

    public function getClientOrderId(): string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId;
        return $this->touch();
    }

    public function getPresetMode(): string
    {
        return $this->presetMode;
    }

    public function setPresetMode(string $presetMode): self
    {
        $this->presetMode = strtolower($presetMode);
        return $this->touch();
    }

    /**
     * @return array<string,mixed>
     */
    public function getQuantization(): array
    {
        return $this->quantization;
    }

    /**
     * @param array<string,mixed> $quantization
     */
    public function setQuantization(array $quantization): self
    {
        $this->quantization = $quantization;
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

    /**
     * @return array<string,mixed>|null
     */
    public function getRawInputs(): ?array
    {
        return $this->rawInputs;
    }

    public function getOrderGroupId(): ?string
    {
        $rawInputs = $this->rawInputs;
        if ($rawInputs === null) {
            return null;
        }

        $groupId = $rawInputs['order_group_id'] ?? null;

        if ($groupId === null && isset($rawInputs['options']) && \is_array($rawInputs['options'])) {
            $options = $rawInputs['options'];
            $groupId = $options['order_group_id'] ?? $options['orderGroupId'] ?? null;
        }

        return $groupId !== null ? (string) $groupId : null;
    }

    /**
     * @param array<string,mixed>|null $rawInputs
     */
    public function setRawInputs(?array $rawInputs): self
    {
        $this->rawInputs = $rawInputs;
        return $this->touch();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getValidationErrors(): ?array
    {
        return $this->validationErrors;
    }

    /**
     * @param array<string,mixed>|null $validationErrors
     */
    public function setValidationErrors(?array $validationErrors): self
    {
        $this->validationErrors = $validationErrors;
        return $this->touch();
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId;
        return $this->touch();
    }

    public function getExchangeOrderId(): ?string
    {
        return $this->exchangeOrderId;
    }

    public function setExchangeOrderId(?string $exchangeOrderId): self
    {
        $this->exchangeOrderId = $exchangeOrderId;
        return $this->touch();
    }

    public function getInternalTradeId(): ?string
    {
        return $this->internalTradeId;
    }

    public function setInternalTradeId(?string $internalTradeId): self
    {
        $this->internalTradeId = self::blankToNull($internalTradeId);
        return $this->touch();
    }

    public function getInternalPositionId(): ?string
    {
        return $this->internalPositionId;
    }

    public function setInternalPositionId(?string $internalPositionId): self
    {
        $this->internalPositionId = self::blankToNull($internalPositionId);
        return $this->touch();
    }

    public function getCorrelationRunId(): ?string
    {
        return $this->correlationRunId;
    }

    public function setCorrelationRunId(?string $correlationRunId): self
    {
        $this->correlationRunId = self::blankToNull($correlationRunId);
        return $this->touch();
    }

    public function getOrchestrationRunId(): ?string
    {
        return $this->orchestrationRunId;
    }

    public function setOrchestrationRunId(?string $orchestrationRunId): self
    {
        $this->orchestrationRunId = self::blankToNull($orchestrationRunId);
        return $this->touch();
    }

    public function getOrchestrationSetId(): ?string
    {
        return $this->orchestrationSetId;
    }

    public function setOrchestrationSetId(?string $orchestrationSetId): self
    {
        $this->orchestrationSetId = self::blankToNull($orchestrationSetId);
        return $this->touch();
    }

    public function getOrchestrationDashboardId(): ?string
    {
        return $this->orchestrationDashboardId;
    }

    public function setOrchestrationDashboardId(?string $orchestrationDashboardId): self
    {
        $this->orchestrationDashboardId = self::blankToNull($orchestrationDashboardId);
        return $this->touch();
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): self
    {
        $this->origin = self::blankToNull($origin) ?? 'legacy';
        return $this->touch();
    }

    public function getReplayOfRunId(): ?string
    {
        return $this->replayOfRunId;
    }

    public function setReplayOfRunId(?string $replayOfRunId): self
    {
        $this->replayOfRunId = self::blankToNull($replayOfRunId);
        return $this->touch();
    }

    public function getReplayOfCorrelationId(): ?string
    {
        return $this->replayOfCorrelationId;
    }

    public function setReplayOfCorrelationId(?string $replayOfCorrelationId): self
    {
        $this->replayOfCorrelationId = self::blankToNull($replayOfCorrelationId);
        return $this->touch();
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function setAttemptNumber(int $attemptNumber): self
    {
        $this->attemptNumber = max(1, $attemptNumber);
        return $this->touch();
    }

    public function getConfigHash(): ?string
    {
        return $this->configHash;
    }

    public function setConfigHash(?string $configHash): self
    {
        $this->configHash = self::blankToNull($configHash);
        return $this->touch();
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): self
    {
        $this->failureReason = $failureReason;
        return $this->touch();
    }

    /**
     * @return Collection<int, OrderProtection>
     */
    public function getProtections(): Collection
    {
        return $this->protections;
    }

    public function addProtection(OrderProtection $protection): self
    {
        if (!$this->protections->contains($protection)) {
            $this->protections->add($protection);
            $protection->setExchange($this->exchange);
            $protection->setMarketType($this->marketType);
            $protection->setOrderIntent($this);
        }
        return $this->touch();
    }

    public function removeProtection(OrderProtection $protection): self
    {
        if ($this->protections->removeElement($protection)) {
            if ($protection->getOrderIntent() === $this) {
                $protection->setOrderIntent(null);
            }
        }
        return $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this->touch();
    }

    // Méthodes utilitaires pour les statuts

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    public function isReadyToSend(): bool
    {
        return $this->status === self::STATUS_READY_TO_SEND;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function markAsValidated(): self
    {
        return $this->setStatus(self::STATUS_VALIDATED);
    }

    public function markAsReadyToSend(): self
    {
        return $this->setStatus(self::STATUS_READY_TO_SEND);
    }

    public function markAsSent(?string $orderId = null): self
    {
        $this->setStatus(self::STATUS_SENT);
        if ($orderId !== null) {
            $this->setOrderId($orderId);
            $this->setExchangeOrderId($orderId);
        }
        $this->setSentAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        return $this->touch();
    }

    public function markAsFailed(string $reason): self
    {
        $this->setStatus(self::STATUS_FAILED);
        $this->setFailureReason($reason);
        return $this->touch();
    }

    public function markAsCancelled(): self
    {
        return $this->setStatus(self::STATUS_CANCELLED);
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
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
