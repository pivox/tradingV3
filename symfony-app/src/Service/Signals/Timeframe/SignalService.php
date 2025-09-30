<?php
// src/Service/Signals/Timeframe/SignalService.php

declare(strict_types=1);


namespace App\Service\Signals\Timeframe;


use App\Entity\Kline;
use Psr\Log\LoggerInterface;


/**
 * Orchestrateur MTF pour la stratégie "scalping" (trading.scalping.yml).
 *
 * API proposée :
 * evaluate([
 * '4h' => Kline[],
 * '1h' => Kline[],
 * '15m'=> Kline[],
 * '5m' => Kline[],
 * '1m' => Kline[],
 * ]): array
 *
 * Règle générale :
 * - On exige l'ALIGNEMENT contexte (4h + 1h).
 * - Exécution si 15m OU 5m donne le même sens.
 * - 1m sert d'affinage : s'il contredit, on annule l'entrée.
 */
final class SignalService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger, // canal 'signals'
        private Signal4hService $signal4h,
        private Signal1hService $signal1h,
        private Signal15mService $signal15m,
        private Signal5mService $signal5m,
        private Signal1mService $signal1m,
    ) {}


    /**
     * Ne calcule **que** le timeframe courant et renvoie uniquement:
     * - `signals[tf]` (bloc du TF courant),
     * - `final.signal` (copie du signal du TF courant),
     * - `status` (PENDING/VALIDATED/FAILED) selon vos règles MTF.
     *
     * @param string  $tf           '4h'|'1h'|'15m'|'5m'|'1m'
     * @param Kline[] $candles      Bougies du TF courant uniquement
     * @param array<string,array{signal:string}> $knownSignals  Signaux déjà connus (4h,1h,15m,5m selon le cas)
     * @return array{signals: array<string,array>, final: array{signal:string}, status: string}
     */
    public function evaluate(string $tf, array $candles, array $knownSignals = []): array
    {
        // 1) Calcul du TF courant uniquement
        $currentEval = match ($tf) {
            '4h'  => $this->signal4h->evaluate($candles),
            '1h'  => $this->signal1h->evaluate($candles),
            '15m' => $this->signal15m->evaluate($candles),
            '5m'  => $this->signal5m->evaluate($candles),
            '1m'  => $this->signal1m->evaluate($candles),
            default => ['signal' => 'NONE', 'trigger' => 'unsupported_tf', 'path' => $tf],
        };
        $this->validationLogger->info(" --- Evaluated signal $tf --- ", ['result' => $currentEval]);

        $curr = strtoupper((string)($currentEval['signal'] ?? 'NONE'));
        $get  = static fn(string $k): string => strtoupper((string)($knownSignals[$k]['signal'] ?? 'NONE'));

        // 2) Statut selon vos règles (correction d’un petit typo : pour 1m on compare 4h,1h,15m,5m et 1m)
        // 4h : LONG/SHORT => PENDING ; sinon FAILED
        // 1h : si 1h == 4h (≠ NONE) => PENDING ; sinon FAILED
        // 15m : si 15m == 4h == 1h => PENDING ; sinon FAILED
        // 5m : si 5m == 15m == 1h == 4h => PENDING ; sinon FAILED
        // 1m : si 1m == 5m == 15m == 1h == 4h => VALIDATED ; sinon FAILED
        $status = 'FAILED';
        switch ($tf) {
            case '4h':
                $status = in_array($curr, ['LONG','SHORT'], true) ? 'PENDING' : 'FAILED';
                break;

            case '1h':
                $s4 = $get('4h');
                $status = ($curr !== 'NONE' && $curr === $s4) ? 'PENDING' : 'FAILED';
                break;

            case '15m':
                $s4 = $get('4h'); $s1 = $get('1h');
                $status = ($curr !== 'NONE' && $curr === $s4 && $s4 === $s1) ? 'PENDING' : 'FAILED';
                break;

            case '5m':
                $s4 = $get('4h'); $s1 = $get('1h'); $s15 = $get('15m');
                $status = ($curr !== 'NONE' && $curr === $s15 && $s15 === $s1 && $s1 === $s4) ? 'VALIDATED' : 'FAILED';
                break;

            case '1m':
                $s4 = $get('4h'); $s1 = $get('1h'); $s15 = $get('15m'); $s5 = $get('5m');
                $status = ($curr !== 'NONE' && $curr === $s5 && $s5 === $s15 && $s15 === $s1 && $s1 === $s4)
                    ? 'VALIDATED'
                    : 'FAILED';
                break;
        }

        $out = [
            'signals' => [ $tf => $currentEval ],
            'final'   => [ 'signal' => $curr ],
            'status'  => $status,
        ];

        // Logs ciblés
        $this->signalsLogger->info('signals.tick', ['tf' => $tf, 'current' => $currentEval, 'status' => $status]);
        ($status === 'FAILED' ? $this->validationLogger->warning(...) : $this->validationLogger->info(...))(
            'validation.status', ['tf' => $tf, 'status' => $status]
        );

        return $out;
    }

    /**
     * Évalue tous les timeframes en cascade et retourne les résultats pour chaque TF.
     *
     * @param array<string, Kline[]> $allCandles Tableau associatif des bougies par TF
     * @return array<string, array{signals: array, final: array, status: string}>
     */
    public function evaluateAll(array $allCandles): array
    {
        $results = [];
        $knownSignals = [];
        foreach (['4h', '1h', '15m', '5m', '1m'] as $tf) {
            if (!isset($allCandles[$tf])) {
                continue;
            }
            $res = $this->evaluate($tf, $allCandles[$tf], $knownSignals);
            $results[$tf] = $res;
            $knownSignals[$tf] = $res['final'];
        }
        return $results;
    }


}
