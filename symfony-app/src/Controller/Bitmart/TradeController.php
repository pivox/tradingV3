<?php
// src/Controller/Bitmart/TradeController.php
declare(strict_types=1);

namespace App\Controller\Bitmart;

use App\Service\Account\Bitmart\BitmartSdkAdapter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

final class TradeController
{
    public function __construct(
        private readonly BitmartSdkAdapter $bitmart,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ouvre une position Futures BitMart (sans sizing).
     *
     * JSON attendu :
     * {
     *   "symbol": "BTCUSDT",
     *   "side": "LONG",                 // LONG | SHORT
     *   "type": "limit",                // limit | market | stop_limit | stop_market
     *   "price": 65000.0,               // requis si type=limit|stop_limit
     *   "size": 0.001,                  // quantité
     *   "post_only": true,              // optionnel (défaut: true)
     *   "reduce_only": false,           // optionnel (ouverture => false)
     *   "position_mode": "isolated",    // (optionnel) si tu gères le mode ailleurs
     *
     *   // Optionnels pour armer TP/SL tout de suite :
     *   "tp1_price": 66000.0,
     *   "tp1_size": 0.001,
     *   "sl_stop": 64000.0              // stop-market (trigger_price)
     * }
     */
    #[Route('/api/bitmart/positions/open', name: 'bm_open_position', methods: ['POST'])]
    public function open(Request $req): JsonResponse
    {
        $p = \json_decode($req->getContent(), true) ?: [];

        $symbol  = (string)($p['symbol'] ?? '');
        $side    = \strtoupper((string)($p['side'] ?? ''));
        $type    = \strtolower((string)($p['type'] ?? 'limit'));
        $price   = isset($p['price']) ? (float)$p['price'] : 0.0;
        $size    = isset($p['size'])  ? (float)$p['size']  : 0.0;
        $post    = (bool)($p['post_only'] ?? true);
        $reduce  = (bool)($p['reduce_only'] ?? false);

        // Validations minimales
        if ($symbol === '' || !\in_array($side, ['LONG','SHORT'], true)) {
            return new JsonResponse(['ok'=>false,'error'=>'symbol ou side invalide'], 422);
        }
        if (!\in_array($type, ['limit','market','stop_limit','stop_market'], true)) {
            return new JsonResponse(['ok'=>false,'error'=>'type invalide'], 422);
        }
        if ($size <= 0.0) {
            return new JsonResponse(['ok'=>false,'error'=>'size doit être > 0'], 422);
        }
        if (\in_array($type, ['limit','stop_limit'], true) && $price <= 0.0) {
            return new JsonResponse(['ok'=>false,'error'=>'price requis pour type limit/stop_limit'], 422);
        }

        // Mapping BitMart
        $isLong     = ($side === 'LONG');
        $bmSide     = $isLong ? 'buy' : 'sell';
        $openAction = $isLong ? 'open_long' : 'open_short';
        $clientOid  = 'e_' . \bin2hex(\random_bytes(6));

        // 1) Entrée
        $entryReq = [
            'symbol'      => $symbol,
            'side'        => $bmSide,          // buy | sell
            'type'        => $type,            // limit | market | stop_limit | stop_market
            'open_type'   => $openAction,      // open_long | open_short
            'size'        => $size,
            'reduce_only' => $reduce,          // ouverture => false
            'post_only'   => $post,
            'client_oid'  => $clientOid,
        ];
        if (\in_array($type, ['limit','stop_limit'], true)) {
            $entryReq['price'] = $price;
        }
        if (\in_array($type, ['stop_limit','stop_market'], true) && isset($p['stop_price'])) {
            $entryReq['trigger_price'] = (float)$p['stop_price'];
        }

        try {
            $entryResp    = $this->bitmart->submitOrder($entryReq);
            $entryOrderId = (string)($entryResp['order_id'] ?? '');
            if ($entryOrderId === '') {
                return new JsonResponse(['ok'=>false,'error'=>"BitMart n'a pas retourné d'order_id"], 502);
            }

            $created = [
                'entry' => ['order_id' => $entryOrderId, 'client_oid' => $clientOid],
                'tp1'   => null,
                'sl'    => null,
            ];

            // 2) TP1 (optionnel, limit reduce-only)
            if (!empty($p['tp1_price']) && !empty($p['tp1_size'])) {
                $tpReq = [
                    'symbol'      => $symbol,
                    'side'        => $isLong ? 'sell' : 'buy',
                    'type'        => 'limit',
                    'price'       => (float)$p['tp1_price'],
                    'size'        => (float)$p['tp1_size'],
                    'open_type'   => $isLong ? 'close_long' : 'close_short',
                    'reduce_only' => true,
                    'client_oid'  => 't_' . \bin2hex(\random_bytes(6)),
                ];
                $tpResp = $this->bitmart->submitOrder($tpReq);
                $created['tp1'] = [
                    'order_id'   => (string)($tpResp['order_id'] ?? ''),
                    'client_oid' => $tpReq['client_oid'],
                ];
            }

            // 3) SL (optionnel, stop-market reduce-only)
            if (!empty($p['sl_stop'])) {
                $slReq = [
                    'symbol'        => $symbol,
                    'side'          => $isLong ? 'sell' : 'buy',
                    'type'          => 'stop_market',
                    'size'          => $size,
                    'open_type'     => $isLong ? 'close_long' : 'close_short',
                    'reduce_only'   => true,
                    'trigger_price' => (float)$p['sl_stop'],
                    'client_oid'    => 's_' . \bin2hex(\random_bytes(6)),
                ];
                $slResp = $this->bitmart->submitOrder($slReq);
                $created['sl'] = [
                    'order_id'   => (string)($slResp['order_id'] ?? ''),
                    'client_oid' => $slReq['client_oid'],
                ];
            }

            return new JsonResponse([
                'ok'      => true,
                'symbol'  => $symbol,
                'side'    => $side,
                'created' => $created,
            ], 200);
        } catch (\Throwable $e) {
            $this->logger->error('BitMart open failed', ['ex'=>$e, 'payload'=>$p]);
            return new JsonResponse(['ok'=>false, 'error'=>$e->getMessage()], 502);
        }
    }
}
