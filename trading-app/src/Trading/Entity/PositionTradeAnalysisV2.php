<?php

declare(strict_types=1);

namespace App\Trading\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vue VERSIONNÉE en lecture seule `position_trade_analysis_v2` (OBS-003 v2,
 * cf. Version20260622000000). Coexiste avec la vue historique v1 (issue #190, bascule
 * progressive) : rapprochement entrée/clôture par identifiants EXACTS (internal_trade_id,
 * trade_id puis position_id, jamais par symbole), lineage d'orchestration, statut de qualité et
 * contrat PnL EXPLICITE.
 *
 * Contrat PnL (issue #190) — on ne présente JAMAIS une valeur estimée comme certifiée :
 *  - `recorded_pnl_usdt` : valeur enregistrée par la source (PAS garantie brute ni nette) ;
 *  - `estimated_net_pnl_usdt` : ESTIMATION best-effort (`recorded - fees - funding -
 *    slippage`) calculée uniquement quand ces composantes sont présentes ;
 *  - `cost_completeness` : `not_applicable` (pas de clôture) | `unknown` (clôturé sans
 *    aucune composante de coût) | `partial` (certaines composantes, mais le contrat #190
 *    complet — brut + frais entrée/sortie séparés + spread + funding signé + borrow —
 *    n'est pas atteignable avec les producteurs actuels) | `complete` (réservé au
 *    contrat #190 complet, jamais émis tant que les producteurs ne le fournissent pas).
 *  Il n'existe donc PAS de `net_pnl_usdt` certifié dans cette vue.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'position_trade_analysis_v2')]
class PositionTradeAnalysisV2
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

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, name: 'run_id')]
    private ?string $runId = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, name: 'correlation_run_id')]
    private ?string $correlationRunId = null;

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

    #[ORM\Column(type: Types::STRING, length: 96, nullable: true, name: 'internal_trade_id')]
    private ?string $internalTradeId = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true, name: 'trade_id')]
    private ?string $tradeId = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, name: 'position_id')]
    private ?string $positionId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, name: 'entry_time')]
    private \DateTimeImmutable $entryTime;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true, name: 'close_time')]
    private ?\DateTimeImmutable $closeTime = null;

    // --- Rapprochement & qualité -------------------------------------------------

    #[ORM\Column(type: Types::STRING, length: 16, name: 'close_match_status')]
    private string $closeMatchStatus = 'unmatched';

    #[ORM\Column(type: Types::STRING, length: 32, name: 'close_matched_by')]
    private string $closeMatchedBy = 'unmatched';

    /** `matched_closed` | `unmatched` (état réel inconnu : ni ouvert confirmé ni clôturé certifié). */
    #[ORM\Column(type: Types::STRING, length: 32, name: 'analysis_status')]
    private string $analysisStatus = 'unmatched';

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

    // --- Résultat / PnL (contrat explicite, jamais de net certifié) --------------

    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'pnl_r')]
    private ?float $pnlR = null;

    /** Valeur ENREGISTRÉE telle quelle — ni brute ni nette garanties (issue #190). */
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

    /** ESTIMATION best-effort, jamais certifiée (cf. {@see getCostCompleteness()}). */
    #[ORM\Column(type: Types::FLOAT, nullable: true, name: 'estimated_net_pnl_usdt')]
    private ?float $estimatedNetPnlUsdt = null;

    /** `not_applicable` | `unknown` | `partial` | `complete` (jamais `complete` à ce stade). */
    #[ORM\Column(type: Types::STRING, length: 16, name: 'cost_completeness')]
    private string $costCompleteness = 'unknown';

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

    public function getInternalTradeId(): ?string
    {
        return $this->internalTradeId;
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

    public function getAnalysisStatus(): string
    {
        return $this->analysisStatus;
    }

    /** Clôture rapprochée par identifiant exact (la seule base d'un trade certifié). */
    public function isMatchedClosed(): bool
    {
        return $this->closeEventId !== null && $this->closeMatchStatus === 'matched';
    }

    public function isUnmatched(): bool
    {
        return !$this->isMatchedClosed();
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

    public function getEstimatedNetPnlUsdt(): ?float
    {
        return $this->estimatedNetPnlUsdt;
    }

    public function getCostCompleteness(): string
    {
        return $this->costCompleteness;
    }

    /** Coûts au contrat #190 complet — jamais vrai tant que les producteurs ne le fournissent pas. */
    public function isCostComplete(): bool
    {
        return $this->costCompleteness === 'complete';
    }
}
