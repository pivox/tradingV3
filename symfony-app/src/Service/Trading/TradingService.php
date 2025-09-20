<?php
namespace App\Service\Trading;

use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use App\Service\Signals\Timeframe\Signal4hService;

class TradingService
{
    public function __construct(
        private TradingParameters $params,
        private KlineRepository $klineRepository,
        private Signal4hService $signal4h,
    ) {}

    /**
     * Récupère/évalue un signal pour un symbole & timeframe.
     * (Ta version existante, inchangée.)
     */
    public function getSignal(string $symbol, string $timeframe, int $limit = 300): array
    {
        // 1) Config YAML
        $config = $this->params->getConfig();

        // 2) Bougies selon timeframe
        $candles = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, $timeframe, $limit);

        // 3) Exécuter le signal (ici spécifique 4h, mais on peut généraliser après)
        $result = $this->signal4h->evaluate($candles);

        // 4) Retourner un tableau métier
        return [
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'limit'     => $limit,
            'config'    => [
                'validation_order' => $config['validation_order'] ?? [],
                'risk'             => $config['risk'] ?? [],
            ],
            'result'    => $result,
        ];
    }

    /**
     * --- MÉTHODES AJOUTÉES CI-DESSOUS ---
     */

    /**
     * Nombre de positions déjà ouvertes.
     * TODO: brancher sur ton broker/DB. Ici on renvoie 0 pour ne pas bloquer.
     */
    public function countOpenPositions(): int
    {
        // EXEMPLE si tu as un PositionRepository :
        // return $this->positionRepository->countOpen();
        return 0;
    }

    /**
     * Prix courant d’un symbole (dernière close disponible).
     * Essaie 1m puis 5m en fallback.
     */
    public function getLastPrice(string $symbol): float
    {
        $candle = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, '1m', 1)[0] ?? null;
        if (!$candle) {
            $candle = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, '5m', 1)[0] ?? null;
        }
        if (!$candle) {
            throw new \RuntimeException("Aucune bougie récente pour $symbol.");
        }

        // Adapte selon ton entité (getClose() ou ['close'])
        if (is_object($candle) && method_exists($candle, 'getClose')) {
            return (float)$candle->getClose();
        }
        if (is_array($candle) && isset($candle['close'])) {
            return (float)$candle['close'];
        }

        throw new \RuntimeException("Impossible de lire le prix de clôture pour $symbol.");
    }

    /**
     * Décide LONG/SHORT.
     * Simple heuristique : si la dernière close > précédente => LONG, sinon SHORT.
     * TODO: remplacer par ton pipeline (ex: lire le sens depuis tes signaux 1m/5m/15m).
     */
    public function decideSide(string $symbol): string
    {
        $candles = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, '1m', 2);
        $c1 = $candles[0] ?? null;
        $c2 = $candles[1] ?? null;

        $close = function($c) {
            if (is_object($c) && method_exists($c, 'getClose')) return (float)$c->getClose();
            if (is_array($c) && isset($c['close'])) return (float)$c['close'];
            return null;
        };

        $last = $close($c1);
        $prev = $close($c2);

        if ($last !== null && $prev !== null) {
            return ($last >= $prev) ? 'LONG' : 'SHORT';
        }

        // Fallback : LONG par défaut
        return 'LONG';
    }

    /**
     * Garde-fou liquidation.
     * TODO: brancher sur ton calcul de prix de liquidation réel (levier, marge, etc.).
     * Ici on fait un check minimal : distance SL >= (minRatio * 0) => toujours OK.
     */
    public function passesLiquidationGuard(string $symbol, string $side, float $entry, float $sl, float $minRatio): bool
    {
        // Exemple de logique si tu connais le prix de liq :
        // $liq = $this->broker->getLiquidationPrice($symbol, $side, $entry, $size, $leverage);
        // $distStop = abs($entry - $sl);
        // $distLiq  = abs($entry - $liq);
        // return $distLiq >= $minRatio * $distStop;

        // Pour l’instant, ne bloque rien.
        return true;
    }

    /**
     * Quantize un prix au tickSize.
     * Par défaut on arrondit à l’inférieur (floor) au multiple de tick.
     */
    public function quantizePrice(float $price, float $tickSize): float
    {
        if ($tickSize <= 0) return $price;
        $steps = floor($price / $tickSize);
        return $steps * $tickSize;
    }

    /**
     * Quantize une quantité au stepSize.
     * Par défaut on arrondit à l’inférieur (floor) au multiple de step.
     */
    public function quantizeQty(float $qty, float $stepSize): float
    {
        if ($stepSize <= 0) return $qty;
        $steps = floor($qty / $stepSize);
        return $steps * $stepSize;
    }

    /**
     * Equity du compte.
     * TODO: brancher sur ton AccountService/broker. Valeur par défaut pour tests.
     */
    public function getEquity(): float
    {
        // EXEMPLE si tu as AccountService :
        // return $this->accountService->getEquity();
        return 10_000.0;
    }

    /**
     * Envoi d’un plan d’ordres (entrée + SL + TP…).
     * TODO: router vers ton broker/exchange (spot/futures) et retourner les IDs.
     */
    public function placeOrderPlan(array $orderPlan): array
    {
        // EXEMPLE :
        // return $this->orderRouter->placePlan($orderPlan);

        // Stub de confirmation immédiate pour ne pas casser le flux :
        return [
            'mock' => true,
            'message' => 'Plan accepté (stub). À implémenter avec le broker.',
            'echo' => $orderPlan,
        ];
    }
}
