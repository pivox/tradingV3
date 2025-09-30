<?php

namespace App\Service\Trading;

use App\Dto\OrderPlan;
use Psr\Log\LoggerInterface;

final class OrderPlanner
{
    public function __construct(private readonly LoggerInterface $logger, private readonly TradingPort $trading) {}


    public function buildScalpingPlan(
        string $symbol,
        string $side, // 'long' | 'short'
        float $entry,
        float $qty,
        float $stop,
        float $tp1,
        float $tp1Portion = 0.60,
        bool $postOnly = true,
        bool $reduceOnly = true,
        array $meta = [],
    ): OrderPlan {
        //dd($symbol, $side, $entry, $qty, $stop, $tp1, $tp1Portion, $postOnly, $reduceOnly, $meta);
        if ($entry <= 0.0) throw new \InvalidArgumentException('entry must be > 0');
        if ($qty <= 0.0) throw new \InvalidArgumentException('qty must be > 0');


        $filters = $this->trading->getFilters($symbol);
        $qz = new Quantizer($filters);
        $isLong = ($side === 'long');


// Quantization directionnelle
        $qEntry = $isLong ? $qz->qPriceFloor($entry) : $qz->qPriceCeil($entry);
        $qTp1 = $isLong ? $qz->qPriceCeil($tp1) : $qz->qPriceFloor($tp1);
        $qStop = $isLong ? $qz->qPriceFloor($stop) : $qz->qPriceCeil($stop);


// Écart minimal 1 tick
        $tick = max($filters->tickSize, 0.0);
        if ($isLong) {
            if (!($qStop < $qEntry)) { $qStop = $qEntry - $tick; }
            if (!($qTp1 > $qEntry)) { $qTp1 = $qEntry + $tick; }
        } else {
            if (!($qStop > $qEntry)) { $qStop = $qEntry + $tick; }
            if (!($qTp1 < $qEntry)) { $qTp1 = $qEntry - $tick; }
        }


// Quantités
        $qQtyTotal = $qz->qQtyFloor(max($qty, 0.0));
        if ($qQtyTotal <= 0.0) throw new \RuntimeException('Quantity after quantization is zero');
        $qTp1Qty = max($qz->qQtyFloor($qQtyTotal * $tp1Portion), $filters->minQty ?? 0.0);
        $qRunnerQty = $qz->qQtyFloor(max($qQtyTotal - $qTp1Qty, 0.0));
        if ($qTp1Qty + $qRunnerQty > $qQtyTotal) $qRunnerQty = $qQtyTotal - $qTp1Qty;
        if ($qTp1Qty <= 0.0 || $qRunnerQty <= 0.0) { $qTp1Qty = $qQtyTotal; $qRunnerQty = 0.0; }


// Notional min
        $notional = $qEntry * $qQtyTotal;
        if (($filters->minNotional ?? 0.0) > 0.0 && $notional < $filters->minNotional) {
            $need = ($filters->minNotional / max($qEntry, 1e-12));
            $qQtyTotal = $qz->qQtyCeil($need);
            $qTp1Qty = $qz->qQtyFloor($qQtyTotal * $tp1Portion);
            $qRunnerQty= $qz->qQtyFloor(max($qQtyTotal - $qTp1Qty, 0.0));
        }


// Intégrité finale
        if ($isLong && !($qStop < $qEntry && $qTp1 > $qEntry)) {
            throw new \RuntimeException("Long: stop must be {$qStop} < {$qEntry} and {$qTp1} > entry {$qEntry}");
        }
        if (!$isLong && !($qStop > $qEntry && $qTp1 < $qEntry)) {
            throw new \RuntimeException("Short: stop must be {$qStop} > {$qEntry} and {$qTp1} < entry {$qEntry}");
        }


        $this->logger->info('OrderPlanner plan built', compact('symbol','side','qEntry','qStop','qTp1','qQtyTotal','qTp1Qty','qRunnerQty','tick'));


        return new OrderPlan(
            symbol: $symbol,
            side: $side,
            entryPrice: $qEntry,
            totalQty: $qQtyTotal,
            tp1Price: $qTp1,
            stopPrice: $qStop,
            tp1Qty: $qTp1Qty,
            runnerQty: $qRunnerQty,
            postOnly: $postOnly,
            reduceOnly: $reduceOnly,
            meta: $meta,
        );
    }
}
