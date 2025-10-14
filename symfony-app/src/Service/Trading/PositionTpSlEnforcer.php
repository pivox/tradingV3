<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Bitmart\Http\BitmartHttpClientPublic;
use Psr\Log\LoggerInterface;
use App\Service\Trading\Idempotency\ClientOrderIdFactory;
use App\Service\Bitmart\Private\OrdersService; // <-- ton service existant qui fait le POST REST

final class PositionTpSlEnforcer
{
    public function __construct(
        private readonly OrdersService $orders,
        private readonly ClientOrderIdFactory $coid,
        private readonly SimpleQuantizer $quantizer,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsLogger,
        private readonly BitmartHttpClientPublic $bitmartHttpClientPublic // helper pour détails contrat
    ) {}

    /**
     * $position attendu (extrait de ta BDD/WS):
     * [
     *   'symbol' => 'BTCUSDT',
     *   'side' => 'long'|'short',
     *   'entry_price' => 113000.2,
     *   'position_id' => 'pos_123' // optionnel
     * ]
     */
    public function enforce(array $position, float $tpTarget, float $slTarget, ?float $tickSize = null): void
    {
        $symbol = (string)($position['symbol'] ?? '');
        $side   = (string)($position['side'] ?? '');
        $entry  = (float)($position['entry_price'] ?? 0.0);

        if ($symbol === '' || ($side !== 'long' && $side !== 'short') || $entry <= 0.0) {
            $this->logger->warning('Position invalide pour TP/SL', compact('symbol','side','entry'));
            return;
        }

        // 1) Quantize minimal
        $tp = $this->quantizer->quantizePrice($symbol, $tpTarget, $tickSize);
        $sl = $this->quantizer->quantizePrice($symbol, $slTarget, $tickSize);

        // 2) Garde-fous sens prix
        if ($side === 'long' && !($sl < $entry && $tp > $entry)) {
            $this->logger->warning('TP/SL invalid LONG', compact('symbol','entry','tp','sl'));
            return;
        }
        if ($side === 'short' && !($sl > $entry && $tp < $entry)) {
            $this->logger->warning('TP/SL invalid SHORT', compact('symbol','entry','tp','sl'));
            return;
        }

        // 3) Idempotence (fenêtre 1h)
        $positionId = isset($position['position_id']) ? (string)$position['position_id'] : null;
        $coidTp = $this->coid->make('tp', $symbol, $side, $positionId, 3600);
        $coidSl = $this->coid->make('sl', $symbol, $side, $positionId, 3600);

        // 4) Payloads Position TP/SL (plan_category=2 + market)
        $payloadTp = [
            'symbol'          => $symbol,
            // 'side' pour close: 3 = sell_close_long, 2 = buy_close_short (selon docs/SDK)
            'side'            => ($side === 'long') ? 3 : 2,
            'order_type'      => 'take_profit',
            'trigger_price'   => (string)$tp,
            'price_type'      => 1,         // 1=last, 2=fair/mark
            'plan_category'   => 2,         // Position TP/SL
            'category'        => 'market',  // défaut plan_category=2
            'client_order_id' => $coidTp,
        ];
        $payloadSl = [
            'symbol'          => $symbol,
            'side'            => ($side === 'long') ? 3 : 2,
            'order_type'      => 'stop_loss',
            'trigger_price'   => (string)$sl,
            'price_type'      => 1,
            'plan_category'   => 2,
            'category'        => 'market',
            'client_order_id' => $coidSl,
        ];

        $this->logger->info('Submitting Position TP/SL', [
            'symbol'=>$symbol,'entry'=>$entry,'tp'=>$tp,'sl'=>$sl,
            'tp_coid'=>$coidTp,'sl_coid'=>$coidSl
        ]);

        // 5) Post via ton OrdersService (au lieu d’appeler ->bitmart->post())
        $resTp = $this->orders->createTpSl($payloadTp);
        $resSl = $this->orders->createTpSl($payloadSl);

        $this->logger->info('Position TP/SL response', ['tp'=>$resTp, 'sl'=>$resSl]);
    }

    public function enforceAuto(array $position): void
    {
        $symbol = (string)($position['symbol'] ?? '');
        $side   = strtolower((string)($position['side'] ?? ''));
        $entry  = (float)($position['entry_price'] ?? 0.0);

        if ($symbol === '' || !in_array($side, ['long','short'], true) || $entry <= 0.0) {
            $this->positionsLogger->warning('[TP/SL] enforceAuto: position invalide', compact('symbol','side','entry'));
            return;
        }

        // 1) Détails contrat pour tick
        $details = $this->bitmartHttpClientPublic->getContractDetails($symbol);
        $tick    = (float)($details['price_precision'] ?? 0.0);
        $ctSize  = (float)($details['contract_size'] ?? 0.0);

        // 2) Si la position porte déjà des prix, on les prend, sinon fallback conf abs_usdt
        $tpPrice = isset($position['tp_price']) ? (float)$position['tp_price'] : null;
        $slPrice = isset($position['sl_price']) ? (float)$position['sl_price'] : null;

        if ($tpPrice && $slPrice) {
            $tpTarget = $tpPrice;
            $slTarget = $slPrice;
        } else {
            $tpAbs     =  10.0;
            $slAbs     =  4.0;
            $size      = (int)($position['size'] ?? 0);
            $qtyNotion = max(1e-9, $size * $ctSize);

            if ($side === 'long') {
                $slTarget = $entry - ($slAbs / $qtyNotion);
                $tpTarget = $entry + ($tpAbs / $qtyNotion);
            } else {
                $slTarget = $entry + ($slAbs / $qtyNotion);
                $tpTarget = $entry - ($tpAbs / $qtyNotion);
            }
        }

        // 3) Appel de la méthode existante
        $this->enforce($position, $tpTarget, $slTarget, $tick);
    }

}
