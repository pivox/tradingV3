<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Dto\ContractDetailsCollection;
use App\Dto\ContractDetailsDto;
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
        $reduceSide = ($side === 'long') ? 3 : 2; // BitMart close side values
        $sizeContracts = (int)($position['size'] ?? 0);
        if ($sizeContracts <= 0) {
            $sizeContracts = 1;
        }

        $payloadTp = [
            'symbol'          => $symbol,
            'side'            => $reduceSide,
            'type'            => 'take_profit',
            'trigger_price'   => (string)$tp,
            'price_type'      => 1,         // 1=last, 2=fair/mark
            'plan_category'   => 2,         // Position TP/SL
            'category'        => 'market',
            'size'            => $sizeContracts,
            'client_order_id' => $coidTp,
        ];
        $payloadSl = [
            'symbol'          => $symbol,
            'side'            => $reduceSide,
            'type'            => 'stop_loss',
            'trigger_price'   => (string)$sl,
            'price_type'      => 1,
            'plan_category'   => 2,
            'category'        => 'market',
            'size'            => $sizeContracts,
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
        $contract = $this->resolveContractDetails($symbol, $details);

        if ($contract === null) {
            $this->positionsLogger->warning('[TP/SL] enforceAuto: aucun détail contrat trouvé', [
                'symbol' => $symbol,
            ]);
            return;
        }

        $tickValue = (float) $contract->pricePrecision;
        $tick = $tickValue > 0.0 ? $tickValue : null;

        $ctSizeValue = (float) $contract->contractSize;
        $ctSize = $ctSizeValue > 0.0 ? $ctSizeValue : 1.0;

        // 2) Si la position porte déjà des prix, on les prend, sinon fallback conf abs_usdt
        $tpPrice = isset($position['tp_price']) ? (float)$position['tp_price'] : null;
        $slPrice = isset($position['sl_price']) ? (float)$position['sl_price'] : null;

        $tpOrderId = $this->extractString($position, ['tp_order_id', 'take_profit_order_id', 'preset_take_profit_order_id']);
        $slOrderId = $this->extractString($position, ['sl_order_id', 'stop_loss_order_id', 'preset_stop_loss_order_id']);

        if ($tpOrderId !== null || $slOrderId !== null) {
            $this->positionsLogger->info('[TP/SL] enforceAuto: contrats TP/SL déjà présents, skip', [
                'symbol' => $symbol,
                'tp_order_id' => $tpOrderId,
                'sl_order_id' => $slOrderId,
            ]);
            return;
        }

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

    private function resolveContractDetails(string $symbol, ContractDetailsCollection $collection): ?ContractDetailsDto
    {
        if ($collection->isEmpty()) {
            return null;
        }

        $contract = $collection->findBySymbol($symbol);
        if ($contract !== null) {
            return $contract;
        }

        return $collection->first();
    }

    /**
     * @param array<string, mixed> $position
     * @param string[] $keys
     */
    private function extractString(array $position, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $position)) {
                continue;
            }

            $value = $position[$key];
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                $stringValue = (string) $value;
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }

        return null;
    }

}
