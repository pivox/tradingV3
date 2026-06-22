<?php

declare(strict_types=1);

namespace App\Trading\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vue en lecture seule `position_trade_analysis` (cf. Version20260622000000).
 *
 * OBS-003 v2 — la vue rapproche désormais une entrée (`order_submitted`) à sa clôture
 * (`position_closed`) par identifiants EXACTS (`trade_id` puis `position_id`, jamais par
 * symbole), et expose le lineage d'orchestration (`correlation_run_id`,
 * `orchestration_run_id`, `dashboard_id`, `set_id`, `exchange`, `market_type`,
 * `mtf_profile`) ainsi qu'une sémantique de PnL explicite (`recorded_pnl_usdt` vs
 * `net_pnl_usdt` + `net_pnl_complete`).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'position_trade_analysis')]
class PositionTradeAnalysis
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT, name: 'entry_event_id')]
    private int $entryEventId;

    #[ORM\Column(type: Types::BIGINT, name: 'close_event_id', nullable: true)]
    private ?int $closeEventId = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $timeframe = null;

    // --- Lineage d'orchestration -------------------------------------------------

    /** Identifiant de corrélation (== `trade_lifecycle_event.run_id`, VARCHAR(64)). */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, name: 'run_id')]
    private ?string $runId = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, name: 'correlation_run_id')]
    private ?string $correlationRunId = null;

    /** Identifiant original du run d'orchestration (peut dépasser 64 ; `extra`). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true, name: 'orchestration_run_id')]
    private ?string $orchestrationRunId = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true, name: 'dashboard_id')]
    private ?string $dashboardId = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true, name: 'set_id')]
    private ?string $setId = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $exchange = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, name: 'market_type')]
    private ?string $marketType = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, name: 'mtf_profile')]
    private ?string $mtfProfile = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true, name: 'trade_id')]
    private ?string $tradeId = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, name: 'position_id')]
    private ?string $positionId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, name: 'entry_time')]
    private \DateTimeImmutable $entryTime;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true, name: 'close_time')]
    private ?\DateTimeImmutable $closeTime = null;

    // --- Rapprochement entrée/clôture -------------------------------------------

    /** `matched` si une clôture exacte est rattachée, sinon `unmatched`. */
    #[ORM\Column(type: Types::STRING, length: 16, name: 'close_match_status')]
    private string $closeMatchStatus = 'unmatched';

    /** `matched_trade_id` | `matched_position_id` | `unmatched`. */
    #[ORM\Column(type: Types::STRING, length: 32, name: 'close_matched_by')]
    private string $closeMatchedBy = 'unmatched';

    // --- Plan / sizing à l'entrée -----------------------------------------------

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'expected_r_multiple')]
    private ?float $expectedRMultiple = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'risk_usdt')]
    private ?float $riskUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'notional_usdt')]
    private ?float $notionalUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'atr_pct_entry')]
    private ?float $atrPctEntry = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'entry_volume_ratio')]
    private ?float $entryVolumeRatio = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true, name: 'snapshot_kline_time')]
    private ?\DateTimeImmutable $snapshotKlineTime = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'entry_rsi')]
    private ?float $entryRsi = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'entry_atr')]
    private ?float $entryAtr = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'entry_macd')]
    private ?float $entryMacd = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'entry_ma9')]
    private ?float $entryMa9 = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'entry_ma21')]
    private ?float $entryMa21 = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'entry_vwap')]
    private ?float $entryVwap = null;

    // --- Résultat / PnL ----------------------------------------------------------

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'pnl_r')]
    private ?float $pnlR = null;

    /**
     * PnL ENREGISTRÉ tel quel par la source : on ne garantit PAS qu'il est net de tous
     * les coûts (frais/funding/slippage). Voir {@see getNetPnlUsdt()}/{@see isNetPnlComplete()}.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'recorded_pnl_usdt')]
    private ?float $recordedPnlUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'pnl_pct')]
    private ?float $pnlPct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'mfe_pct')]
    private ?float $mfePct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'mae_pct')]
    private ?float $maePct = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'holding_time_sec')]
    private ?float $holdingTimeSec = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'fees_usdt')]
    private ?float $feesUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'funding_usdt')]
    private ?float $fundingUsdt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'slippage_usdt')]
    private ?float $slippageUsdt = null;

    /** PnL net UNIQUEMENT si recorded + tous les coûts sont présents, sinon `null`. */
    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'net_pnl_usdt')]
    private ?float $netPnlUsdt = null;

    #[ORM\Column(type: Types::BOOLEAN, name: 'net_pnl_complete')]
    private bool $netPnlComplete = false;

    public function getEntryEventId(): int
    {
        return $this->entryEventId;
    }

    public function getCloseEventId(): ?int
    {
        return $this->closeEventId;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getTimeframe(): ?string
    {
        return $this->timeframe;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function getCorrelationRunId(): ?string
    {
        return $this->correlationRunId;
    }

    public function getOrchestrationRunId(): ?string
    {
        return $this->orchestrationRunId;
    }

    public function getDashboardId(): ?string
    {
        return $this->dashboardId;
    }

    public function getSetId(): ?string
    {
        return $this->setId;
    }

    public function getExchange(): ?string
    {
        return $this->exchange;
    }

    public function getMarketType(): ?string
    {
        return $this->marketType;
    }

    public function getMtfProfile(): ?string
    {
        return $this->mtfProfile;
    }

    public function getTradeId(): ?string
    {
        return $this->tradeId;
    }

    public function getPositionId(): ?string
    {
        return $this->positionId;
    }

    public function getEntryTime(): \DateTimeImmutable
    {
        return $this->entryTime;
    }

    public function getCloseTime(): ?\DateTimeImmutable
    {
        return $this->closeTime;
    }

    public function getCloseMatchStatus(): string
    {
        return $this->closeMatchStatus;
    }

    public function getCloseMatchedBy(): string
    {
        return $this->closeMatchedBy;
    }

    public function isClosed(): bool
    {
        return $this->closeEventId !== null;
    }

    public function isMatched(): bool
    {
        return $this->closeMatchStatus === 'matched';
    }

    public function getExpectedRMultiple(): ?float
    {
        return $this->expectedRMultiple;
    }

    public function getRiskUsdt(): ?float
    {
        return $this->riskUsdt;
    }

    public function getNotionalUsdt(): ?float
    {
        return $this->notionalUsdt;
    }

    public function getAtrPctEntry(): ?float
    {
        return $this->atrPctEntry;
    }

    public function getEntryVolumeRatio(): ?float
    {
        return $this->entryVolumeRatio;
    }

    public function getSnapshotKlineTime(): ?\DateTimeImmutable
    {
        return $this->snapshotKlineTime;
    }

    public function getEntryRsi(): ?float
    {
        return $this->entryRsi;
    }

    public function getEntryAtr(): ?float
    {
        return $this->entryAtr;
    }

    public function getEntryMacd(): ?float
    {
        return $this->entryMacd;
    }

    public function getEntryMa9(): ?float
    {
        return $this->entryMa9;
    }

    public function getEntryMa21(): ?float
    {
        return $this->entryMa21;
    }

    public function getEntryVwap(): ?float
    {
        return $this->entryVwap;
    }

    public function getPnlR(): ?float
    {
        return $this->pnlR;
    }

    public function getRecordedPnlUsdt(): ?float
    {
        return $this->recordedPnlUsdt;
    }

    /**
     * Alias de compatibilité ascendante pour les consommateurs existants (template Twig
     * de reporting). Renvoie le PnL ENREGISTRÉ — pas garanti net (cf. {@see getNetPnlUsdt()}).
     *
     * @deprecated Utiliser {@see getRecordedPnlUsdt()} (ou {@see getNetPnlUsdt()}).
     */
    public function getPnlUsdt(): ?float
    {
        return $this->recordedPnlUsdt;
    }

    public function getPnlPct(): ?float
    {
        return $this->pnlPct;
    }

    public function getMfePct(): ?float
    {
        return $this->mfePct;
    }

    public function getMaePct(): ?float
    {
        return $this->maePct;
    }

    public function getHoldingTimeSec(): ?float
    {
        return $this->holdingTimeSec;
    }

    public function getFeesUsdt(): ?float
    {
        return $this->feesUsdt;
    }

    public function getFundingUsdt(): ?float
    {
        return $this->fundingUsdt;
    }

    public function getSlippageUsdt(): ?float
    {
        return $this->slippageUsdt;
    }

    public function getNetPnlUsdt(): ?float
    {
        return $this->netPnlUsdt;
    }

    public function isNetPnlComplete(): bool
    {
        return $this->netPnlComplete;
    }
}
