<?php

declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\Entity\TradeZoneEvent;
use App\Repository\TradeZoneEventRepository;
use App\TradeEntry\Dto\ZoneSkipEventDto;

final class ZoneSkipPersistenceService
{
    public function __construct(
        private readonly TradeZoneEventRepository $repository,
    ) {}

    public function persist(ZoneSkipEventDto $dto): void
    {
        $category = $dto->category ?? $this->inferCategory($dto->zoneDevPct, $dto->zoneMaxDevPct);
        $proposedMax = $dto->proposedZoneMaxPct ?? $this->proposeZoneMax($dto->zoneDevPct, $dto->zoneMaxDevPct, $category);

        $event = new TradeZoneEvent(
            symbol: $dto->symbol,
            reason: $dto->reason,
            zoneMin: $dto->zoneMin,
            zoneMax: $dto->zoneMax,
            candidatePrice: $dto->candidatePrice,
            zoneDevPct: $dto->zoneDevPct,
            zoneMaxDevPct: $dto->zoneMaxDevPct,
            happenedAt: $dto->happenedAt,
        );

        $event
            ->setDecisionKey($dto->decisionKey)
            ->setTimeframe($dto->timeframe)
            ->setConfigProfile($dto->configProfile)
            ->setAtrPct($dto->atrPct)
            ->setSpreadBps($dto->spreadBps)
            ->setVolumeRatio($dto->volumeRatio)
            ->setVwapDistancePct($dto->vwapDistancePct)
            ->setEntryZoneWidthPct($dto->entryZoneWidthPct)
            ->setMtfContext($dto->mtfContext)
            ->setMtfLevel($dto->mtfLevel)
            ->setProposedZoneMaxPct($proposedMax)
            ->setCategory($category);

        $this->repository->save($event, true);
    }

    private function inferCategory(float $zoneDevPct, float $zoneMaxDevPct): string
    {
        $threshold = max($zoneMaxDevPct, 1e-9);
        if ($zoneDevPct <= 1.05 * $threshold) {
            return 'close_to_threshold';
        }

        if ($zoneDevPct <= 1.5 * $threshold) {
            return 'moderate_gap';
        }

        return 'far_outside';
    }

    private function proposeZoneMax(float $zoneDevPct, float $zoneMaxDevPct, string $category): ?float
    {
        return match ($category) {
            'close_to_threshold' => max($zoneDevPct, $zoneMaxDevPct * 1.1),
            'moderate_gap' => $zoneDevPct,
            default => null,
        };
    }
}
