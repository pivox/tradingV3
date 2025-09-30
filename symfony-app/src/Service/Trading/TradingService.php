<?php

namespace App\Service\Trading;

use App\Dto\ExchangeFilters;
use App\Repository\ContractPipelineRepository;
use App\Service\Account\Bitmart\BitmartBalanceService;
use App\Service\Account\Bitmart\BitmartFuturesClient;
use App\Service\Account\Bitmart\BitmartSdkAdapter;
use App\Service\Config\TradingParameters;
use Psr\Log\LoggerInterface;

final class TradingService implements TradingPort
{
    public function __construct(
        private readonly BitmartSdkAdapter $bitmart,
        private readonly LoggerInterface $logger,
        private readonly BitmartBalanceService $balance,
        private readonly TradingParameters $tradingParameters,
        private readonly LoggerInterface $positionsLogger,
        private readonly ContractPipelineRepository $contracts,
        private readonly BitmartFuturesClient $bitmartClient
    )
    {
    }

    public function getEquity(): float
    {
        return 10_000.0;
    }


    public function getAvailableUSDT(): float
    {
        $data = $this->balance->getFuturesAssets(); // V2 KEYED
        foreach ($data as $asset) {
            if (isset($asset['currency']) && strtoupper((string)$asset['currency']) === 'USDT') {
                $available = (float)($asset['available_balance'] ?? 0.0);
                if ($available > 0.0) {
                    return $available;
                }
            }
        }

        return 0.0;
    }

    public function getFilters(string $symbol): ExchangeFilters
    {
        $f = $this->tryFetchExchangeFilters($symbol); // méthode existante si dispo
        if ($f && $f->tickSize > 0 && $f->stepSize > 0) return $f;

        // Fallback conf
        $cfg = $this->tradingParameters->getConfig();
        $tick = (float)($cfg['quantization']['tick_size'] ?? 0.01);
        $step = (float)($cfg['quantization']['step_size'] ?? 0.001);
        return new \App\Dto\ExchangeFilters(
            tickSize: $tick,
            stepSize: $step,
            minNotional: null
        );
    }


    public function passesLiquidationGuard(
        string $symbol,
        string $side,   // 'long'|'short'
        float $entry,
        float $stop,
        float $minRatio
    ): bool {
        // Stop invalide → refuse
        $dStop = abs($entry - $stop);
        if (!is_finite($entry) || !is_finite($stop) || $entry <= 0.0 || $dStop <= 0.0) {
            return false;
        }

        // Si impossible d’estimer la liq → fallback: borne sur le levier
        if (!$this->canComputeLiquidation($symbol)) {
            $lev   = $this->currentLeverageFor($symbol) ?? 1;
            $maxLv = (int)($this->tradingParameters->getConfig()['liquidation_guard']['fallback_max_leverage'] ?? 10);
            return $lev <= max(1, $maxLv);
        }

        $lev = $this->currentLeverageFor($symbol) ?? 1;

        // Taux de maintenance (approx). Tu peux raffiner par symbole / palier.
        $mmr = 0.005;
        $cfg = $this->tradingParameters->getConfig();
        if (isset($cfg['risk']['maintenance_rate'])) {
            $mmr = max(0.0, (float)$cfg['risk']['maintenance_rate']);
        }

        $liq = $this->liquidationPriceApprox($side, $entry, $lev, $mmr);
        if ($liq <= 0.0 || !is_finite($liq)) {
            // dernier garde-fou
            $maxLv = (int)($cfg['liquidation_guard']['fallback_max_leverage'] ?? 10);
            return $lev <= max(1, $maxLv);
        }

        $dLiq = abs($entry - $liq);

        // Exige: distance(liq) >= minRatio * distance(stop)
        return $dLiq >= $minRatio * $dStop;
    }



    public function placeOrderPlan(array $plan): array
    {
        // 0) Normalisation + validations
        $entryCfg = (array)($plan['entry'] ?? []);
        if (\function_exists('array_is_list') && \array_is_list($entryCfg)) {
            $entryCfg = ['price' => (float)($entryCfg[0] ?? 0), 'quantity' => (float)($entryCfg[1] ?? 0)];
        }

        $symbol     = (string)($plan['symbol'] ?? '');
        $sideUpper  = strtoupper((string)($plan['side'] ?? ''));
        $isLong     = $sideUpper === 'LONG';
        $entryPrice = (float)($entryCfg['price'] ?? 0);
        $entryQtyF  = (float)($entryCfg['quantity'] ?? 0);

        if ($symbol === '' || !\in_array($sideUpper, ['LONG','SHORT'], true)) {
            throw new \InvalidArgumentException('symbol/side invalides');
        }
        if ($entryPrice <= 0 || $entryQtyF <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'entry.price et entry.quantity doivent être > 0 (got price=%s qty=%s)',
                $entryPrice, $entryQtyF
            ));
        }

        // V2: size = int (contrats), price = string
        $entryQty  = (int)\floor($entryQtyF);          // tronque au contrat inférieur
        if ($entryQty <= 0) {
            throw new \InvalidArgumentException('entry.quantity (size) doit être un entier >= 1 (contrats).');
        }
        $entryPxStr = \number_format($entryPrice, 8, '.', ''); // string

        $clientOidEntry = 'e_'.\bin2hex(\random_bytes(6));
        $mode           = !empty($plan['postOnly']) ? 4 /* Maker Only */ : 1 /* GTC */;

        // 1) (Recommandé) Régler levier/marge AVANT (ne pas envoyer open_type à submit-order)
        //    On n’échoue pas si pas dispo
        try {
            if (method_exists($this->bitmart, 'submitLeverageV2')) {
                $lev = (string)($plan['leverage'] ?? '1');
                $openType = !empty($plan['marginMode']) && $plan['marginMode'] === 'cross' ? 'cross' : 'isolated';
                $this->bitmart->submitLeverageV2($symbol, $lev, $openType);
            }
        } catch (\Throwable $e) {
            // log soft, on continue: l’exchange peut garder le dernier réglage
            // $this->logger->warning('submitLeverageV2 failed', ['ex' => $e]);
        }

        // 2) Entrée (submit-order V2) — SANS open_type
        // one-way: 1=buy (LONG), 4=sell (SHORT)
        $sideIntEntry = $isLong ? 1 : 4;

        $entryResp = $this->bitmart->submitOrderV2(
            symbol:   $symbol,
            side:     $sideIntEntry,
            size:     $entryQty,
            type:     'limit',
            price:    $entryPxStr,
            leverage: (string)($plan['leverage'] ?? '1'),
            mode:     $mode,
            clientOid:$clientOidEntry
        );
        $this->positionsLogger->info("Order Entry Response", ['response' => $entryResp]);

        $entryOrderId = (string)($entryResp['order_id'] ?? '');
        if ($entryOrderId === '') {
            throw new \RuntimeException('BitMart: échec création ordre d’entrée (pas d\'order_id)');
        }

        $created = [
            'entry' => ['order_id' => $entryOrderId, 'client_oid' => $clientOidEntry],
            'tp1'   => null,
            'sl'    => null,
        ];

        // 3) TP1 (limit reduce-only) — one-way: 3 = sell reduceOnly, 2 = buy reduceOnly
        if (!empty($plan['legs']['tp1'])) {
            $tp = (array)$plan['legs']['tp1'];
            $tpQtyF = (float)($tp['quantity'] ?? 0);
            $tpPx   = (float)($tp['price'] ?? 0);
            if ($tpQtyF > 0 && $tpPx > 0) {
                $tpQty   = (int)\floor($tpQtyF);
                if ($tpQty <= 0) {
                    throw new \InvalidArgumentException('tp1.quantity doit être un entier >= 1');
                }
                $tpPxStr = \number_format($tpPx, 8, '.', '');
                $tpSide  = $isLong ? 3 /* sell reduceOnly */ : 2 /* buy reduceOnly */;
                $tpOid   = 't_'.\bin2hex(\random_bytes(6));

                $tpResp = $this->bitmart->submitOrderV2(
                    symbol:   $symbol,
                    side:     $tpSide,
                    size:     $tpQty,
                    type:     'limit',
                    price:    $tpPxStr,
                    leverage: (string)($plan['leverage'] ?? '1'),
                    mode:     1,            // GTC pour TP
                    clientOid:$tpOid
                );
                $created['tp1'] = [
                    'order_id'   => (string)($tpResp['order_id'] ?? ''),
                    'client_oid' => $tpOid,
                ];
            }
        }

        // 4) SL (plan order) — utiliser submit-plan-order V2 (pas submit-order)
        if (!empty($plan['legs']['sl'])) {
            $sl = (array)$plan['legs']['sl'];
            $slQtyF  = (float)($sl['quantity'] ?? $entryQty);
            $slStop  = (float)($sl['stopPrice'] ?? 0);
            if ($slQtyF > 0 && $slStop > 0) {
                $slQty   = (int)\floor($slQtyF);
                if ($slQty <= 0) {
                    throw new \InvalidArgumentException('sl.quantity doit être un entier >= 1');
                }
                $slStopStr = \number_format($slStop, 8, '.', '');
                $slOid     = 's_'.\bin2hex(\random_bytes(6));

                // Si l’adapter a une méthode dédiée :
                if (method_exists($this->bitmart, 'submitPlanOrderV2')) {
                    // type: 'stop_market' équivaut côté V2 plan order avec exécution "market" à trigger
                    $slResp = $this->bitmart->submitPlanOrderV2([
                        'symbol'          => $symbol,
                        'client_order_id' => $slOid,
                        'side'            => $isLong ? 3 : 2, // close (reduce) côté inverse
                        'type'            => 'market',        // exécution au trigger
                        'mode'            => 1,               // GTC
                        'trigger_price'   => $slStopStr,
                        'size'            => $slQty,
                    ]);
                    $created['sl'] = [
                        'order_id'   => (string)($slResp['order_id'] ?? ''),
                        'client_oid' => $slOid,
                    ];
                } else {
                    // Sinon, on informe clairement
                    throw new \RuntimeException(
                        'SL nécessite submit-plan-order V2. Ajoute BitmartSdkAdapter::submitPlanOrderV2() (POST /contract/private/submit-plan-order).'
                    );
                }
            }
        }

        return [
            'status' => 'submitted',
            'symbol' => $symbol,
            'side'   => $sideUpper,
            'entry'  => [
                'price'      => (float)$entryPxStr,
                'quantity'   => $entryQty,
                'order_id'   => $created['entry']['order_id'],
                'client_oid' => $created['entry']['client_oid'],
            ],
            'tp1'    => $created['tp1'],
            'sl'     => $created['sl'],
        ];
    }

    /** Mémoire locale facultative (mise à jour quand tu appelles submit-leverage) */
    private array $perSymbolLeverage = []; // ex: ['BTCUSDT' => 5]

    /** Si tu as un setter ailleurs, appelle-le après submit-leverage */
    public function rememberLeverage(string $symbol, int $lev): void
    {
        $this->perSymbolLeverage[strtoupper($symbol)] = max(1, $lev);
    }

    /** true si on sait approximer la liq (levier connu) */
    private function canComputeLiquidation(string $symbol): bool
    {
        $lev = $this->currentLeverageFor($symbol);
        return $lev !== null && $lev >= 1;
    }

    /** Essaie: mémoire locale -> conf fallback -> null */
    private function currentLeverageFor(string $symbol): ?int
    {
        $s = strtoupper($symbol);
        if (isset($this->perSymbolLeverage[$s])) {
            return (int)$this->perSymbolLeverage[$s];
        }
        // fallback conservateur: borne max autorisée par la conf (on ne "sait" pas le levier exact)
        $maxLev = (int)($this->tradingParameters->getConfig()['liquidation_guard']['fallback_max_leverage'] ?? 10);
        return $maxLev > 0 ? $maxLev : null;
    }

    /** Essaie d’obtenir tick/step/min depuis Contract ; sinon fallback YAML quantization */
    private function tryFetchExchangeFilters(string $symbol): ExchangeFilters
    {
        $c = $this->contracts->find(strtoupper($symbol));
        if ($c) {
            // pricePrecision/volPrecision dans ta table: convertis en tick/step
            $tick = null; $step = null;

            // Plusieurs projets stockent "pricePrecision" comme nb décimales → tick = 10^-dec
            $p = $c->getPricePrecision();
            if (is_numeric($p) && $p >= 0) {
                $tick = pow(10, -(int)$p);
            }

            // Même logique pour la quantité
            $q = $c->getVolPrecision();
            if (is_numeric($q) && $q >= 0) {
                $step = pow(10, -(int)$q);
            }

            $minNotional = null;
            // si tu as min notionnel ou min volume sur l’entité :
            $minVol = $c->getMinVolume();
            if ($minVol !== null && $tick !== null) {
                // Notionnel mini (approx) = minVol * dernier prix connu (si dispo)
                $last = $this->bitmartClient->getLastPrice($symbol); // ← dernier prix live (ou null)
                $this->positionsLogger->info("************************************************************************************************" );
                $this->positionsLogger->info("************************************************************************************************" );
                $this->positionsLogger->info("************************************************************************************************" );
                $this->positionsLogger->info("Last price for $symbol is " . ($last ?? 'null'));
                $this->positionsLogger->info("************************************************************************************************" );
                $this->positionsLogger->info("************************************************************************************************" );
                $this->positionsLogger->info("************************************************************************************************" );
                if ($last !== null) {
                    $minNotional = (float)$minVol * (float)$last;
                }
            }

            if (($tick ?? 0.0) > 0.0 && ($step ?? 0.0) > 0.0) {
                return new ExchangeFilters(
                    tickSize: (float)$tick,
                    stepSize: (float)$step,
                    minNotional: $minNotional
                );
            }
        }

        // Fallback YAML
        $cfg  = $this->tradingParameters->getConfig();
        $tick = (float)($cfg['quantization']['tick_size'] ?? 0.01);
        $step = (float)($cfg['quantization']['step_size'] ?? 0.001);

        return new ExchangeFilters(
            tickSize: $tick,
            stepSize: $step,
            minNotional: null
        );
    }

    /**
     * Approx prix de liquidation (isolated, linéaire USDT) :
     * LONG  ≈ entry * (1 - 1/lev + mmr)
     * SHORT ≈ entry * (1 + 1/lev - mmr)
     * mmr par défaut 0.5%, surcharge si tu as des paliers de risque.
     */
    private function liquidationPriceApprox(string $side, float $entry, int $lev, float $mmr = 0.005): float
    {
        $side = strtolower($side);
        if ($lev <= 0 || $entry <= 0) return 0.0;

        if ($side === 'long') {
            return $entry * (1.0 - 1.0/$lev + $mmr);
        }
        // short
        return $entry * (1.0 + 1.0/$lev - $mmr);
    }
}
