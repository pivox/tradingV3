<?php

namespace App\Service\Persister;

use App\Entity\Contract;
use App\Entity\Exchange;
use App\Service\Exchange\Bitmart\Dto\ContractDto;
use Doctrine\ORM\EntityManagerInterface;

class ContractPersister
{
    public function __construct(private EntityManagerInterface $em) {}

    public function persistFromDto(ContractDto $dto, string $exchangeName): Contract
    {
        // 1) Exchange
        $exchange = $this->em->getRepository(Exchange::class)->find($exchangeName);
        if (!$exchange) {
            $exchange = (new Exchange())->setName($exchangeName);
            $this->em->persist($exchange);
        }

        // 2) Contract (clé primaire = symbol)
        $contract = $this->em->getRepository(Contract::class)->find($dto->symbol);
        if (!$contract) {
            $contract = (new Contract())->setSymbol($dto->symbol);
            $this->em->persist($contract);
        }

        // 3) Conversions helpers
        $msToDate = function (?int $ms): ?\DateTimeImmutable {
            if (!$ms || $ms <= 0) return null;
            // ms -> seconds
            $sec = (int) floor($ms / 1000);
            return (new \DateTimeImmutable())->setTimestamp($sec);
        };
        $secToDate = function (?int $s): ?\DateTimeImmutable {
            if (!$s || $s <= 0) return null;
            return (new \DateTimeImmutable())->setTimestamp($s);
        };
        $toFloat = fn($v): ?float => ($v === null || $v === '') ? null : (float) $v;
        $toInt   = fn($v): ?int   => ($v === null || $v === '') ? null : (int) $v;

        // 4) delist_time : parfois en secondes sur /details ; on gère aussi le cas ms par sécurité
        $delistTime = null;
        if (isset($dto->delistTime)) {
            $delistTime = ($dto->delistTime > 1_000_000_000_000)
                ? $msToDate((int) $dto->delistTime)
                : $secToDate((int) $dto->delistTime);
        } elseif (isset($dto->delistTimeSec)) {
            $delistTime = $secToDate((int) $dto->delistTimeSec);
        } elseif (isset($dto->delistTimeMs)) {
            $delistTime = $msToDate((int) $dto->delistTimeMs);
        }

        // 5) Hydratation expressive (tous les champs connus de Contract)
        $contract
            ->updateCoreInfo(
                baseCurrency:       $dto->baseCurrency ?? null,
                quoteCurrency:      $dto->quoteCurrency ?? null,
                indexName:          $dto->indexName ?? null,
                contractSize:       $toFloat($dto->contractSize ?? null),
                pricePrecision:     $toFloat($dto->pricePrecision ?? null),
                volPrecision:       $toFloat($dto->volPrecision ?? null),
                lastPrice:          $toFloat($dto->lastPrice ?? null)
            )
            ->updateProductTypeFromApi($dto->productType ?? null)
            ->updateContractTimestamps(
                openTimestamp:      isset($dto->openTimestampMs)   ? $msToDate($dto->openTimestampMs)   : null,
                expireTimestamp:    isset($dto->expireTimestampMs) ? $msToDate($dto->expireTimestampMs) : null,
                settleTimestamp:    isset($dto->settleTimestampMs) ? $msToDate($dto->settleTimestampMs) : null,
            )
            ->updateLeverageBounds(
                minLeverage:        $toInt($dto->minLeverage ?? null),
                maxLeverage:        $toInt($dto->maxLeverage ?? null)
            )
            ->updateVolumeLimits(
                minVolume:          $toInt($dto->minVolume ?? null),
                maxVolume:          $toInt($dto->maxVolume ?? null),
                marketMaxVolume:    $toInt($dto->marketMaxVolume ?? null)
            )
            ->updateFundingInfo(
                fundingRate:        $toFloat($dto->fundingRate ?? null),
                expectedFundingRate:$toFloat($dto->expectedFundingRate ?? null),
                fundingTime:        isset($dto->fundingTimeMs) ? $msToDate($dto->fundingTimeMs) : null,
                fundingIntervalHours:$toInt($dto->fundingIntervalHours ?? null)
            )
            ->updateOpenInterest(
                openInterest:       $toInt($dto->openInterest ?? null),
                openInterestValue:  $toFloat($dto->openInterestValue ?? null)
            )
            ->updateDailyStats(
                indexPrice:         $toFloat($dto->indexPrice ?? null),
                high24h:            $toFloat($dto->high24h ?? null),
                low24h:             $toFloat($dto->low24h ?? null),
                change24h:          $toFloat($dto->change24h ?? null),
                turnover24h:        $toFloat($dto->turnover24h ?? null),
                volume24h:          $toInt($dto->volume24h ?? null)
            )
            ->updateLifecycle(
                status:             $dto->status ?? null,
                delistTime:         $delistTime
            );

        return $contract;


        return $contract;
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
