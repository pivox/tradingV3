<?php

declare(strict_types=1);

namespace App\Domain\Position\Service;

use App\Entity\Position;
use App\Repository\PositionRepository;
use Psr\Log\LoggerInterface;

final class PositionUpdateService
{
    public function __construct(
        private readonly PositionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{symbol?:string,side?:string,size?:mixed,avg_price?:mixed,leverage?:mixed,unrealized_pnl?:mixed,status?:string,event_time_ms?:int,raw?:array} $event
     */
    public function handleEvent(array $event): void
    {
        $symbol = (string)($event['symbol'] ?? '');
        $side = strtoupper((string)($event['side'] ?? ''));
        if ($symbol === '' || ($side !== 'LONG' && $side !== 'SHORT')) {
            $this->logger->warning('[PositionUpdate] Ignored event (missing symbol/side)', $event);
            return;
        }

        $entity = $this->repository->findOneBySymbolSide($symbol, $side);
        if ($entity === null) {
            $entity = new Position($symbol, $side);
        }

        $size = $this->toNumericString($event['size'] ?? null);
        $avg = $this->toNumericString($event['avg_price'] ?? null);
        $lev = $this->toIntOrNull($event['leverage'] ?? null);
        $unreal = $this->toNumericString($event['unrealized_pnl'] ?? null);

        if ($size !== null) { $entity->setSize($size); }
        if ($avg !== null) { $entity->setAvgEntryPrice($avg); }
        if ($lev !== null) { $entity->setLeverage($lev); }
        if ($unreal !== null) { $entity->setUnrealizedPnl($unreal); }

        // Statut dérivé de la taille
        if ($size === null || (float)$size === 0.0) {
            $entity->setStatus('CLOSED');
        } else {
            $entity->setStatus('OPEN');
        }

        $entity->mergePayload(['last_event' => $event['raw'] ?? $event]);

        $this->repository->upsert($entity);

        $this->logger->debug('[PositionUpdate] Position updated', [
            'symbol' => $symbol,
            'side' => $side,
            'size' => $size,
            'avg_price' => $avg,
            'leverage' => $lev,
        ]);
    }

    private function toNumericString(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (is_int($value) || is_float($value)) { return (string)$value; }
        if (is_string($value)) { return $value; }
        return null;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') { return null; }
        if (is_int($value)) { return $value; }
        if (is_string($value) && is_numeric($value)) { return (int)$value; }
        return null;
    }
}


