<?php

declare(strict_types=1);

namespace App\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Repository\TradeLifecycleEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeLifecycleEventRepository::class)]
#[ORM\Table(name: 'trade_lifecycle_event')]
class TradeLifecycleEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $symbol;

    #[ORM\Column(length: 32)]
    private string $eventType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $runId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientOrderId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $positionId = null;

    #[ORM\Column(name: 'internal_trade_id', length: 96, nullable: true)]
    private ?string $internalTradeId = null;

    #[ORM\Column(name: 'internal_position_id', length: 96, nullable: true)]
    private ?string $internalPositionId = null;

    #[ORM\Column(name: 'correlation_run_id', length: 96, nullable: true)]
    private ?string $correlationRunId = null;

    #[ORM\Column(name: 'orchestration_run_id', length: 255, nullable: true)]
    private ?string $orchestrationRunId = null;

    #[ORM\Column(name: 'orchestration_set_id', length: 96, nullable: true)]
    private ?string $orchestrationSetId = null;

    #[ORM\Column(name: 'orchestration_dashboard_id', length: 96, nullable: true)]
    private ?string $orchestrationDashboardId = null;

    #[ORM\Column(name: 'origin', length: 24, options: ['default' => 'legacy'])]
    private string $origin = 'legacy';

    #[ORM\Column(name: 'replay_of_run_id', length: 255, nullable: true)]
    private ?string $replayOfRunId = null;

    #[ORM\Column(name: 'replay_of_correlation_id', length: 96, nullable: true)]
    private ?string $replayOfCorrelationId = null;

    #[ORM\Column(name: 'attempt_number', type: Types::INTEGER, options: ['default' => 1])]
    private int $attemptNumber = 1;

    #[ORM\Column(name: 'config_hash', length: 128, nullable: true)]
    private ?string $configHash = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $side = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 15, nullable: true)]
    private ?string $qty = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 15, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $timeframe = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $configProfile = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $configVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $planId = null;

    #[ORM\Column(length: 32, options: ['default' => 'bitmart'])]
    private string $exchange = 'bitmart';

    #[ORM\Column(name: 'market_type', length: 32, options: ['default' => 'perpetual'])]
    private string $marketType = 'perpetual';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $accountId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reasonCode = null;

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $extra = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $happenedAt;

    public function __construct(string $symbol, string $eventType, ?\DateTimeImmutable $happenedAt = null)
    {
        $this->symbol = strtoupper($symbol);
        $this->eventType = strtolower($eventType);
        $this->happenedAt = $happenedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function setRunId(?string $runId): self
    {
        $this->runId = $runId;

        return $this;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): self
    {
        $this->orderId = $orderId !== null ? strtoupper($orderId) : null;

        return $this;
    }

    public function getClientOrderId(): ?string
    {
        return $this->clientOrderId;
    }

    public function setClientOrderId(?string $clientOrderId): self
    {
        $this->clientOrderId = $clientOrderId !== null ? strtoupper($clientOrderId) : null;

        return $this;
    }

    public function getPositionId(): ?string
    {
        return $this->positionId;
    }

    public function setPositionId(?string $positionId): self
    {
        $this->positionId = $positionId;

        return $this;
    }

    public function getInternalTradeId(): ?string
    {
        return $this->internalTradeId;
    }

    public function setInternalTradeId(?string $internalTradeId): self
    {
        $this->internalTradeId = $internalTradeId !== null && trim($internalTradeId) !== ''
            ? trim($internalTradeId)
            : null;

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

    public function getCorrelationRunId(): ?string
    {
        return $this->correlationRunId;
    }

    public function setCorrelationRunId(?string $correlationRunId): self
    {
        $this->correlationRunId = self::blankToNull($correlationRunId);

        return $this;
    }

    public function getOrchestrationRunId(): ?string
    {
        return $this->orchestrationRunId;
    }

    public function setOrchestrationRunId(?string $orchestrationRunId): self
    {
        $this->orchestrationRunId = self::blankToNull($orchestrationRunId);

        return $this;
    }

    public function getOrchestrationSetId(): ?string
    {
        return $this->orchestrationSetId;
    }

    public function setOrchestrationSetId(?string $orchestrationSetId): self
    {
        $this->orchestrationSetId = self::blankToNull($orchestrationSetId);

        return $this;
    }

    public function getOrchestrationDashboardId(): ?string
    {
        return $this->orchestrationDashboardId;
    }

    public function setOrchestrationDashboardId(?string $orchestrationDashboardId): self
    {
        $this->orchestrationDashboardId = self::blankToNull($orchestrationDashboardId);

        return $this;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): self
    {
        $this->origin = self::blankToNull($origin) ?? 'legacy';

        return $this;
    }

    public function getReplayOfRunId(): ?string
    {
        return $this->replayOfRunId;
    }

    public function setReplayOfRunId(?string $replayOfRunId): self
    {
        $this->replayOfRunId = self::blankToNull($replayOfRunId);

        return $this;
    }

    public function getReplayOfCorrelationId(): ?string
    {
        return $this->replayOfCorrelationId;
    }

    public function setReplayOfCorrelationId(?string $replayOfCorrelationId): self
    {
        $this->replayOfCorrelationId = self::blankToNull($replayOfCorrelationId);

        return $this;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function setAttemptNumber(int $attemptNumber): self
    {
        $this->attemptNumber = max(1, $attemptNumber);

        return $this;
    }

    public function getConfigHash(): ?string
    {
        return $this->configHash;
    }

    public function setConfigHash(?string $configHash): self
    {
        $this->configHash = self::blankToNull($configHash);

        return $this;
    }

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function setSide(?string $side): self
    {
        $this->side = $side !== null ? strtoupper($side) : null;

        return $this;
    }

    public function getQty(): ?string
    {
        return $this->qty;
    }

    public function setQty(?string $qty): self
    {
        $this->qty = $qty;

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

    public function getTimeframe(): ?string
    {
        return $this->timeframe;
    }

    public function setTimeframe(?string $timeframe): self
    {
        $this->timeframe = $timeframe !== null ? strtolower($timeframe) : null;

        return $this;
    }

    public function getConfigProfile(): ?string
    {
        return $this->configProfile;
    }

    public function setConfigProfile(?string $configProfile): self
    {
        $this->configProfile = $configProfile;

        return $this;
    }

    public function getConfigVersion(): ?string
    {
        return $this->configVersion;
    }

    public function setConfigVersion(?string $configVersion): self
    {
        $this->configVersion = $configVersion;

        return $this;
    }

    public function getPlanId(): ?string
    {
        return $this->planId;
    }

    public function setPlanId(?string $planId): self
    {
        $this->planId = $planId;

        return $this;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function setExchange(Exchange|string|null $exchange): self
    {
        $this->exchange = $exchange instanceof Exchange ? $exchange->value : strtolower($exchange ?? 'bitmart');

        return $this;
    }

    public function getMarketType(): string
    {
        return $this->marketType;
    }

    public function setMarketType(MarketType|string|null $marketType): self
    {
        $this->marketType = $marketType instanceof MarketType ? $marketType->value : strtolower($marketType ?? 'perpetual');

        return $this;
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function setAccountId(?string $accountId): self
    {
        $this->accountId = $accountId;

        return $this;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function setReasonCode(?string $reasonCode): self
    {
        $this->reasonCode = $reasonCode;

        return $this;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getExtra(): ?array
    {
        return $this->extra;
    }

    /**
     * @param array<string,mixed>|null $extra
     */
    public function setExtra(?array $extra): self
    {
        $this->extra = $extra;

        return $this;
    }

    public function getHappenedAt(): \DateTimeImmutable
    {
        return $this->happenedAt;
    }

    public function setHappenedAt(\DateTimeImmutable $happenedAt): self
    {
        $this->happenedAt = $happenedAt;

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
