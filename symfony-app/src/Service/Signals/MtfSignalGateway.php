<?php
declare(strict_types=1);

namespace App\Service\Signals;

use App\Repository\KlineRepository;
use App\Service\Signals\Timeframe\Signal15mService;
use App\Service\Signals\Timeframe\Signal1hService;
use App\Service\Signals\Timeframe\Signal1mService;
use App\Service\Signals\Timeframe\Signal4hService;
use App\Service\Signals\Timeframe\Signal5mService;

final class MtfSignalGateway
{
    public function __construct(
        private readonly Signal4hService $s4h,
        private readonly Signal1hService $s1h,
        private readonly Signal15mService $s15,
        private readonly Signal5mService $s5,
        private readonly Signal1mService $s1,
    ) {}

    public function validate4h(string $symbol, KlineRepository $klines): bool
    {
        $candles = $klines->fetchRecent($symbol, 240, 300);
        // On délègue à validateContext() ajouté au service 4h (patch ci-dessous)
        return $this->s4h->validateContext($candles);
    }

    public function validate(string $symbol, string $tf, KlineRepository $klines): bool
    {
        $map = [
            '1h'  => [$this->s1h,  300],
            '15m' => [$this->s15,  300],
            '5m'  => [$this->s5,   500],
            '1m'  => [$this->s1,  1000],
        ];
        if (!isset($map[$tf])) return false;

        [$svc, $limit] = $map[$tf];
        $candles = $klines->fetchRecent($symbol, $tf, $limit);

        // Tous les services TF d’exécution exposeront validateExecution() (patch ci-dessous)
        return $svc->validateExecution($candles);
    }

    public function inferSide(string $symbol): ?string
    {
        // 1m prioritaire ; fallback 15m
        return $this->s1->sideHint() ?? $this->s15->sideHint();
    }

    public function entryPrice(string $symbol): ?float
    {
        // 1m prioritaire ; fallback 15m
        return $this->s1->entryHint() ?? $this->s15->entryHint();
    }
}
