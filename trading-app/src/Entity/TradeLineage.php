<?php

declare(strict_types=1);

namespace App\Entity;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Repository\TradeLineageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeLineageRepository::class)]
#[ORM\Table(name: 'trade_lineage')]
#[ORM\UniqueConstraint(name: 'ux_trade_lineage_internal_trade_id', columns: ['internal_trade_id'])]
#[ORM\UniqueConstraint(name: 'ux_trade_lineage_order_intent', columns: ['order_intent_id'])]
#[ORM\UniqueConstraint(name: 'ux_trade_lineage_venue_client_order', columns: ['exchange', 'market_type', 'client_order_id'])]
#[ORM\Index(name: 'idx_trade_lineage_venue_exchange_order', columns: ['exchange', 'market_type', 'exchange_order_id'])]
#[ORM\Index(name: 'idx_trade_lineage_venue_position', columns: ['exchange', 'market_type', 'position_id'])]
#[ORM\Index(name: 'idx_trade_lineage_run_set', columns: ['run_id', 'orchestration_set_id'])]
final class TradeLineage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'internal_trade_id', type: Types::STRING, length: 96)]
    private string $internalTradeId;

    #[ORM\OneToOne(targetEntity: OrderIntent::class)]
    #[ORM\JoinColumn(name: 'order_intent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?OrderIntent $orderIntent = null;

    #[ORM\Column(name: 'client_order_id', type: Types::STRING, length: 96)]
    private string $clientOrderId;

    #[ORM\Column(name: 'exchange_order_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $exchangeOrderId = null;

    #[ORM\Column(name: 'position_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $positionId = null;

    #[ORM\Column(name: 'internal_position_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $internalPositionId = null;

    #[ORM\Column(name: 'run_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $runId = null;

    #[ORM\Column(name: 'correlation_run_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $correlationRunId = null;

    #[ORM\Column(name: 'orchestration_run_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $orchestrationRunId = null;

    #[ORM\Column(name: 'orchestration_set_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $orchestrationSetId = null;

    #[ORM\Column(name: 'orchestration_dashboard_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $orchestrationDashboardId = null;

    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'bitmart'])]
    private string $exchange = 'bitmart';

    #[ORM\Column(name: 'market_type', type: Types::STRING, length: 32, options: ['default' => 'perpetual'])]
    private string $marketType = 'perpetual';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $side = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $profile = null;

    #[ORM\Column(type: Types::STRING, length: 24, options: ['default' => 'legacy'])]
    private string $origin = 'legacy';

    #[ORM\Column(name: 'replay_of_run_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $replayOfRunId = null;

    #[ORM\Column(name: 'replay_of_correlation_id', type: Types::STRING, length: 96, nullable: true)]
    private ?string $replayOfCorrelationId = null;

    #[ORM\Column(name: 'attempt_number', type: Types::INTEGER, options: ['default' => 1])]
    private int $attemptNumber = 1;

    #[ORM\Column(name: 'config_hash', type: Types::STRING, length: 128, nullable: true)]
    private ?string $configHash = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $internalTradeId, string $clientOrderId, string $symbol)
    {
        $this->internalTradeId = $internalTradeId;
        $this->clientOrderId = $clientOrderId;
        $this->symbol = strtoupper($symbol);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInternalTradeId(): string
    {
        return $this->internalTradeId;
    }

    public function getOrderIntent(): ?OrderIntent
    {
        return $this->orderIntent;
    }

    public function setOrderIntent(?OrderIntent $orderIntent): self
    {
        $this->orderIntent = $orderIntent;

        return $this->touch();
    }

    public function getClientOrderId(): string
    {
        return $this->clientOrderId;
    }

    public function getExchangeOrderId(): ?string
    {
        return $this->exchangeOrderId;
    }

    public function setExchangeOrderId(?string $exchangeOrderId): self
    {
        $this->exchangeOrderId = self::blankToNull($exchangeOrderId);

        return $this->touch();
    }

    public function getPositionId(): ?string
    {
        return $this->positionId;
    }

    public function setPositionId(?string $positionId): self
    {
        $this->positionId = self::blankToNull($positionId);

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

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function setRunId(?string $runId): self
    {
        $this->runId = self::blankToNull($runId);

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

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function setSide(?string $side): self
    {
        $this->side = self::blankToNull($side !== null ? strtoupper($side) : null);

        return $this->touch();
    }

    public function getProfile(): ?string
    {
        return $this->profile;
    }

    public function setProfile(?string $profile): self
    {
        $this->profile = self::blankToNull($profile);

        return $this->touch();
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): self
    {
        $this->origin = strtolower(trim($origin)) !== '' ? strtolower(trim($origin)) : 'legacy';

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

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
