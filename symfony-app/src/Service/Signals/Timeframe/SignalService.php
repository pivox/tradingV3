<?php

namespace App\Service\Signals\Timeframe;

class SignalService
{

    public function __construct(
        private Signal4hService $signal4hService,
        private Signal1hService $signal1hService,
        private Signal15mService $signal15mService,
        private Signal5mService $signal5mService,
        private Signal1mService $signal1mService,
    )
    {
    }

    public function evaluate(array $klines, $timeframe): array|false
    {
        return match ($timeframe) {
            '4h' => $this->signal4hService->evaluate($klines),
            '1h' => $this->signal1hService->evaluate($klines),
            '15m' => $this->signal15mService->evaluate($klines),
            '5m' => $this->signal5mService->evaluate($klines),
            '1m' => $this->signal1mService->evaluate($klines),
            default => false,
        };
        // Implémentation spécifique à chaque timeframe
        return false;
    }

    public function validateExecution(array $klines, $timeframe): bool
    {
        return match ($timeframe) {
            '4h' => $this->signal4hService->validateContext($klines),
            '1h' => $this->signal1hService->validateContext($klines),
            '15m' => $this->signal15mService->validateExecution($klines),
            '5m' => $this->signal5mService->validateExecution($klines),
            '1m' => $this->signal1mService->validateExecution($klines),
            default => false,
        };
        // Implémentation spécifique à chaque timeframe
        return false;
    }
}
