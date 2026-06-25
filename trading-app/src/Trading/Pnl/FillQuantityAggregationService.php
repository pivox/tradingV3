<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

use App\Entity\FillCostLedgerEntry;
use App\Repository\FillCostLedgerEntryRepository;

final readonly class FillQuantityAggregationService
{
    public const DEFAULT_QUANTITY_TOLERANCE = 0.00000001;

    public function __construct(
        private ?FillCostLedgerEntryRepository $ledger = null,
        private float $quantityTolerance = self::DEFAULT_QUANTITY_TOLERANCE,
    ) {
    }

    public function aggregateByTradeVenue(
        string $internalTradeId,
        string $exchange,
        string $marketType,
    ): FillQuantityAggregationResult {
        if (!$this->ledger instanceof FillCostLedgerEntryRepository) {
            throw new \LogicException('A fill-cost ledger repository is required for aggregateByTradeVenue().');
        }

        return $this->aggregateEntries(
            $this->ledger->findByInternalTradeIdAndVenue($internalTradeId, $exchange, $marketType),
            $internalTradeId,
            $exchange,
            $marketType,
        );
    }

    /**
     * @param iterable<FillCostLedgerEntry> $entries
     */
    public function aggregateEntries(
        iterable $entries,
        string $internalTradeId,
        string $exchange,
        string $marketType,
    ): FillQuantityAggregationResult {
        $exchange = strtolower(trim($exchange));
        $marketType = strtolower(trim($marketType));

        $entryFills = [];
        $exitFills = [];
        $flags = [];
        $seenFillFingerprints = [];
        $costs = [
            'feeUsdt' => null,
            'fundingUsdt' => null,
            'spreadCostUsdt' => null,
            'slippageCostUsdt' => null,
            'borrowCostUsdt' => null,
            'liquidationFeeUsdt' => null,
        ];

        foreach ($entries as $entry) {
            if (!$entry instanceof FillCostLedgerEntry) {
                continue;
            }
            if ($entry->getInternalTradeId() !== $internalTradeId) {
                continue;
            }
            if ($entry->getExchange() !== $exchange || $entry->getMarketType() !== $marketType) {
                continue;
            }

            $entryFlags = $entry->getQualityFlags();
            if ($this->isCancelledOrCorrected($entryFlags)) {
                $flags[] = 'cancelled_fill_ignored';
                continue;
            }

            $duplicateState = $this->duplicateState($entry, $seenFillFingerprints);
            if ($duplicateState === 'duplicate') {
                $flags[] = 'duplicate_fill_ignored';
                continue;
            }
            if ($duplicateState === 'conflict') {
                $flags[] = 'fill_conflict';
                continue;
            }

            $this->addCosts($costs, $entry);

            $role = strtolower($entry->getFillRole());
            if ($role === 'entry') {
                $entryFills[] = $entry;
                continue;
            }
            if ($role === 'exit') {
                $exitFills[] = $entry;
            }
        }

        usort($entryFills, self::sortByOccurrence(...));
        usort($exitFills, self::sortByOccurrence(...));

        $entryAggregate = $this->aggregateFillSide($entryFills);
        $exitAggregate = $this->aggregateFillSide($exitFills);
        $remainingQty = null;
        if ($entryAggregate['qty'] !== null && $exitAggregate['qty'] !== null) {
            $remainingQty = $entryAggregate['qty'] - $exitAggregate['qty'];
        } elseif ($entryAggregate['qty'] !== null) {
            $remainingQty = $entryAggregate['qty'];
        }

        [$quantityStatus, $statusFlags, $positionFullyClosed] = $this->quantityStatus(
            $entryAggregate['qty'],
            $exitAggregate['qty'],
            $remainingQty,
            $flags,
        );
        $flags = array_values(array_unique([...$flags, ...$statusFlags]));

        return new FillQuantityAggregationResult(
            internalTradeId: $internalTradeId,
            exchange: $exchange,
            marketType: $marketType,
            entryFirstFillAt: $entryAggregate['firstAt'],
            entryLastFillAt: $entryAggregate['lastAt'],
            entryQty: $entryAggregate['qty'],
            entryVwap: $entryAggregate['vwap'],
            exitFirstFillAt: $exitAggregate['firstAt'],
            exitLastFillAt: $exitAggregate['lastAt'],
            exitQty: $exitAggregate['qty'],
            exitVwap: $exitAggregate['vwap'],
            remainingQty: $remainingQty !== null && abs($remainingQty) <= $this->quantityTolerance ? 0.0 : $remainingQty,
            positionFullyClosed: $positionFullyClosed,
            quantityStatus: $quantityStatus,
            quantityQualityFlags: $flags,
            feeUsdt: $costs['feeUsdt'],
            fundingUsdt: $costs['fundingUsdt'],
            spreadCostUsdt: $costs['spreadCostUsdt'],
            slippageCostUsdt: $costs['slippageCostUsdt'],
            borrowCostUsdt: $costs['borrowCostUsdt'],
            liquidationFeeUsdt: $costs['liquidationFeeUsdt'],
        );
    }

    /**
     * @param list<string> $qualityFlags
     */
    private function isCancelledOrCorrected(array $qualityFlags): bool
    {
        foreach ($qualityFlags as $flag) {
            if (\in_array($flag, ['fill_cancelled', 'fill_corrected', 'fill_reversed', 'voided'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $seenFillFingerprints
     */
    private function duplicateState(FillCostLedgerEntry $entry, array &$seenFillFingerprints): ?string
    {
        $identity = $entry->getExchangeFillId();
        if ($identity === null) {
            return null;
        }

        $fingerprint = implode('|', [
            $entry->getFillRole(),
            (string) $entry->getPrice(),
            (string) $entry->getQuantity(),
            (string) $entry->getFeeUsdt(),
            $entry->getOccurredAt()->format(\DateTimeInterface::ATOM),
        ]);
        if (!isset($seenFillFingerprints[$identity])) {
            $seenFillFingerprints[$identity] = $fingerprint;

            return null;
        }

        return $seenFillFingerprints[$identity] === $fingerprint ? 'duplicate' : 'conflict';
    }

    /**
     * @param array{feeUsdt:?float,fundingUsdt:?float,spreadCostUsdt:?float,slippageCostUsdt:?float,borrowCostUsdt:?float,liquidationFeeUsdt:?float} $costs
     */
    private function addCosts(array &$costs, FillCostLedgerEntry $entry): void
    {
        $this->addCost($costs['feeUsdt'], $entry->getFeeUsdt());
        $this->addCost($costs['fundingUsdt'], $entry->getFundingUsdt());
        $this->addCost($costs['spreadCostUsdt'], $entry->getSpreadCostUsdt());
        $this->addCost($costs['slippageCostUsdt'], $entry->getSlippageCostUsdt());
        $this->addCost($costs['borrowCostUsdt'], $entry->getBorrowCostUsdt());
        $this->addCost($costs['liquidationFeeUsdt'], $entry->getLiquidationFeeUsdt());
    }

    private function addCost(?float &$aggregate, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $aggregate = ($aggregate ?? 0.0) + (float) $value;
    }

    /**
     * @param list<FillCostLedgerEntry> $fills
     * @return array{firstAt:?\DateTimeImmutable,lastAt:?\DateTimeImmutable,qty:?float,vwap:?float}
     */
    private function aggregateFillSide(array $fills): array
    {
        if ($fills === []) {
            return [
                'firstAt' => null,
                'lastAt' => null,
                'qty' => null,
                'vwap' => null,
            ];
        }

        $quantity = 0.0;
        $notional = 0.0;
        foreach ($fills as $fill) {
            $fillQuantity = $fill->getQuantity() !== null ? (float) $fill->getQuantity() : null;
            $fillPrice = $fill->getPrice() !== null ? (float) $fill->getPrice() : null;
            if ($fillQuantity === null || $fillPrice === null || $fillQuantity <= 0.0 || $fillPrice <= 0.0) {
                continue;
            }

            $quantity += $fillQuantity;
            $notional += $fillQuantity * $fillPrice;
        }

        return [
            'firstAt' => $fills[0]->getOccurredAt(),
            'lastAt' => $fills[\count($fills) - 1]->getOccurredAt(),
            'qty' => $quantity > 0.0 ? $quantity : null,
            'vwap' => $quantity > 0.0 ? $notional / $quantity : null,
        ];
    }

    /**
     * @param list<string> $existingFlags
     * @return array{0:string,1:list<string>,2:bool}
     */
    private function quantityStatus(?float $entryQty, ?float $exitQty, ?float $remainingQty, array $existingFlags): array
    {
        if (\in_array('fill_conflict', $existingFlags, true)) {
            return ['fill_conflict', ['fill_conflict'], false];
        }
        if ($entryQty === null) {
            return ['missing_entry_fill', ['missing_entry_fill'], false];
        }
        if ($exitQty === null || $remainingQty === null) {
            return ['open_position', ['missing_exit_fill', 'position_not_fully_closed'], false];
        }
        if ($exitQty - $entryQty > $this->quantityTolerance) {
            return ['quantity_mismatch', ['exit_qty_exceeds_entry_qty'], false];
        }
        if (abs($remainingQty) <= $this->quantityTolerance) {
            return ['complete', [], true];
        }

        return ['open_position', ['position_not_fully_closed'], false];
    }

    private static function sortByOccurrence(FillCostLedgerEntry $left, FillCostLedgerEntry $right): int
    {
        return [$left->getOccurredAt()->getTimestamp(), $left->getFillId()]
            <=> [$right->getOccurredAt()->getTimestamp(), $right->getFillId()];
    }
}
