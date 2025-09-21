<?php
// src/Service/Signals/Timeframe/SignalService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use Psr\Log\LoggerInterface;

/**
 * Orchestrateur MTF pour la stratégie "scalping" (trading.scalping.yml).
 *
 * API proposée :
 *   evaluate([
 *     '4h' => Kline[],
 *     '1h' => Kline[],
 *     '15m'=> Kline[],
 *     '5m' => Kline[],
 *     '1m' => Kline[],
 *   ]): array
 *
 * Règle générale :
 *   - On exige l'ALIGNEMENT contexte (4h + 1h).
 *   - Exécution si 15m OU 5m donne le même sens.
 *   - 1m sert d'affinage : s'il contredit, on annule l'entrée.
 */
final class SignalService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Signal4hService $signal4h,
        private Signal1hService $signal1h,
        private Signal15mService $signal15m,
        private Signal5mService $signal5m,
        private Signal1mService $signal1m,
    ) {}

    /**
     * @param array{
     *   4h: Kline[],
     *   1h: Kline[],
     *   15m: Kline[],
     *   5m: Kline[],
     *   1m: Kline[]
     * } $candlesByTf
     * @return array{
     *   context_4h: array,
     *   context_1h: array,
     *   exec_15m: array,
     *   exec_5m: array,
     *   micro_1m: array,
     *   final: array{signal:string,trigger:string,path:string},
     * }
     */
    public function evaluate(array $candlesByTf): array
    {
        $eval4h = $this->signal4h->evaluate($candlesByTf['4h'] ?? []);
        $eval1h = $this->signal1h->evaluate($candlesByTf['1h'] ?? []);

        // Contexte : on impose même sens non-NONE
        $contextSignal = 'NONE';
        $contextTrigger = '';
        if ($eval4h['signal'] !== 'NONE' && $eval4h['signal'] === $eval1h['signal']) {
            $contextSignal = $eval4h['signal'];
            $contextTrigger = 'context_4h_1h_align';
        }

        $eval15m = $this->signal15m->evaluate($candlesByTf['15m'] ?? []);
        $eval5m  = $this->signal5m->evaluate($candlesByTf['5m']  ?? []);

        // Exécution : 15m ou 5m dans le même sens que le contexte
        $execSignal = 'NONE'; $execTrigger = '';
        if ($contextSignal !== 'NONE') {
            if ($eval15m['signal'] === $contextSignal) {
                $execSignal = $contextSignal;
                $execTrigger = '15m_confirms_' . strtolower($contextSignal);
            } elseif ($eval5m['signal'] === $contextSignal) {
                $execSignal = $contextSignal;
                $execTrigger = '5m_confirms_' . strtolower($contextSignal);
            }
        }

        // Micro-structure 1m : si elle contredit l'exécution, on annule
        $eval1m = $this->signal1m->evaluate($candlesByTf['1m'] ?? []);
        $finalSignal = $execSignal; $finalTrigger = $execTrigger; $finalPath = 'mtf_scalping';

        if ($execSignal !== 'NONE' && $eval1m['signal'] !== 'NONE' && $eval1m['signal'] !== $execSignal) {
            $finalSignal = 'NONE';
            $finalTrigger = '1m_contradicts';
        }

        $out = [
            'context_4h' => $eval4h,
            'context_1h' => $eval1h,
            'exec_15m'   => $eval15m,
            'exec_5m'    => $eval5m,
            'micro_1m'   => $eval1m,
            'final'      => ['signal' => $finalSignal, 'trigger' => $finalTrigger, 'path' => $finalPath],
        ];

        $this->signalsLogger->info('signals.tick', $out);
        if ($finalSignal === 'NONE') {
            $this->validationLogger->warning('validation.violation', $out);
        } else {
            $this->validationLogger->info('validation.ok', $out);
        }

        return $out;
    }
}
