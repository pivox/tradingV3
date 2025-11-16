<?php

declare(strict_types=1);

namespace App\Service;

final class TradeCycleRebuilder
{
    /**
     * @param array<int,array<string,mixed>> $trades
     * @return array<int,array<string,mixed>>
     */
    public function buildCycles(array $trades): array
    {
        // 1) tri global par symbol + create_time
        usort($trades, static function (array $a, array $b): int {
            $s = strcmp((string)($a['symbol'] ?? ''), (string)($b['symbol'] ?? ''));
            if ($s !== 0) {
                return $s;
            }

            $ta = (int)($a['create_time'] ?? 0);
            $tb = (int)($b['create_time'] ?? 0);

            return $ta <=> $tb;
        });

        $cycles = [];
        $positionQtyBySymbol = [];
        $currentCycleBySymbol = [];

        foreach ($trades as $t) {
            $symbol = (string)($t['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $side = (int)($t['side'] ?? 0);
            $vol  = (float)($t['vol'] ?? 0.0);

            if ($vol <= 0) {
                continue;
            }

            $signedQty = $this->signedQty($side, $vol);

            $positionQty = $positionQtyBySymbol[$symbol] ?? 0.0;
            $currentCycle = $currentCycleBySymbol[$symbol] ?? null;

            // début de cycle
            if ($positionQty == 0.0) {
                $direction = $signedQty > 0 ? 'long' : 'short';
                $currentCycle = [
                    'symbol'       => $symbol,
                    'direction'    => $direction,
                    'entry_trades' => [],
                    'exit_trades'  => [],
                ];
            }

            if ($currentCycle === null) {
                // sécurité, ne devrait pas arriver
                $direction = $signedQty > 0 ? 'long' : 'short';
                $currentCycle = [
                    'symbol'       => $symbol,
                    'direction'    => $direction,
                    'entry_trades' => [],
                    'exit_trades'  => [],
                ];
            }

            // on est en position ? (avant d'ajouter ce trade)
            if ($positionQty == 0.0) {
                // tous les premiers trades de cycle sont des entries
                $currentCycle['entry_trades'][] = $t;
            } elseif ($positionQty > 0.0) {
                // déjà long
                if ($signedQty > 0) {
                    $currentCycle['entry_trades'][] = $t; // scale-in long
                } else {
                    $currentCycle['exit_trades'][] = $t;  // fermeture partielle ou totale
                }
            } elseif ($positionQty < 0.0) {
                // déjà short
                if ($signedQty < 0) {
                    $currentCycle['entry_trades'][] = $t; // scale-in short
                } else {
                    $currentCycle['exit_trades'][] = $t;  // fermeture partielle ou totale
                }
            }

            // mise à jour de la position
            $positionQty += $signedQty;
            $positionQtyBySymbol[$symbol] = $positionQty;
            $currentCycleBySymbol[$symbol] = $currentCycle;

            // si on revient à flat, on finalise le cycle
            if ($positionQty == 0.0) {
                $cycles[] = $this->finalizeCycle($currentCycle);
                unset($currentCycleBySymbol[$symbol]);
            }
        }

        return $cycles;
    }

    private function signedQty(int $side, float $vol): float
    {
        // One-way mode : 1/2 = BUY, 3/4 = SELL
        // (tu peux raffiner pour gérer reduce_only séparément)
        return match ($side) {
            1, 2 => +$vol,
            3, 4 => -$vol,
            default => 0.0,
        };
    }

    /**
     * @param array<string,mixed> $cycle
     * @return array<string,mixed>
     */
    private function finalizeCycle(array $cycle): array
    {
        $entryVol = 0.0;
        $entryPv  = 0.0;

        $exitVol = 0.0;
        $exitPv  = 0.0;

        $realisedPnl = 0.0;
        $feesTotal   = 0.0;

        foreach ($cycle['entry_trades'] as $t) {
            $v  = (float)$t['vol'];
            $p  = (float)$t['price'];
            $fv = (float)$t['paid_fees'];

            $entryVol += $v;
            $entryPv  += $v * $p;
            $feesTotal += $fv;
        }

        foreach ($cycle['exit_trades'] as $t) {
            $v  = (float)$t['vol'];
            $p  = (float)$t['price'];
            $fv = (float)$t['paid_fees'];
            $rp = (float)$t['realised_profit'];

            $exitVol += $v;
            $exitPv  += $v * $p;
            $feesTotal += $fv;
            $realisedPnl += $rp;
        }

        $entryPrice = $entryVol > 0 ? $entryPv / $entryVol : 0.0;
        $exitPrice  = $exitVol > 0 ? $exitPv / $exitVol : 0.0;

        $kind = 'flat';
        if ($realisedPnl > 0.0) {
            $kind = 'tp';
        } elseif ($realisedPnl < 0.0) {
            $kind = 'sl';
        }

        return [
            'symbol'           => $cycle['symbol'],
            'direction'        => $cycle['direction'],
            'entry_price'      => $entryPrice,
            'entry_qty'        => $entryVol,
            'exit_price'       => $exitPrice,
            'exit_qty'         => $exitVol,
            'pnl_realised'     => $realisedPnl,
            'fees_total'       => $feesTotal,
            'kind'             => $kind,         // tp / sl / flat
            'entry_trades_raw' => $cycle['entry_trades'],
            'exit_trades_raw'  => $cycle['exit_trades'],
        ];
    }
}
