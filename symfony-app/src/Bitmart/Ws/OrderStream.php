<?php
declare(strict_types=1);

namespace App\Bitmart\Ws;

use Psr\Log\LoggerInterface;

/**
 * Écoute les ordres Futures via le canal privé "futures/order".
 * Reçoit chaque changement d’état et trade partiel/total.
 */
final class OrderStream
{
    public function __construct(
        private readonly PrivateWsClient $wsClient,
        private readonly LoggerInterface $logger,
    ) {}

    /** Lance l’écoute. */
    public function listen(callable $onOrderEvent): void
    {
        $this->wsClient->run(['futures/order']);
        // $onOrderEvent($event) sera appelé avec un événement normalisé (cf. normalize ci-dessous).
    }

    /** Normalise un event "futures/order" selon la doc. */
    public static function normalize(array $wsPayload): array
    {
        // Format attendu (doc) :
        // { "group":"futures/order", "data":[ { "action": <int>, "order": { ... } } ] }
        $events = [];
        foreach ((array)($wsPayload['data'] ?? []) as $row) {
            $o = (array)($row['order'] ?? []);
            $events[] = [
                'action'          => (int)($row['action'] ?? 0), // 1=match, 2=submit, 3=cancel, ...
                'order_id'        => (string)($o['order_id'] ?? ''),
                'client_order_id' => (string)($o['client_order_id'] ?? ''),
                'symbol'          => (string)($o['symbol'] ?? ''),
                'side'            => (int)($o['side'] ?? 0),
                'type'            => (string)($o['type'] ?? ''), // limit/market/plan_order/...
                'state'           => (int)($o['state'] ?? 0),    // 1=status_approval, 2=status_check, 4=status_finish, etc.
                'price'           => isset($o['price']) ? (float)$o['price'] : null,
                'size'            => isset($o['size']) ? (float)$o['size'] : null,
                'deal_avg_price'  => isset($o['deal_avg_price']) ? (float)$o['deal_avg_price'] : null,
                'deal_size'       => isset($o['deal_size']) ? (float)$o['deal_size'] : null,
                'leverage'        => isset($o['leverage']) ? (float)$o['leverage'] : null,
                'open_type'       => (string)($o['open_type'] ?? ''), // cross/isolated
                'last_trade'      => (array)($o['last_trade'] ?? []), // { lastTradeID, fillQty, fillPrice, fee, feeCcy }
                'position_mode'   => (string)($o['position_mode'] ?? ''),
                'update_time_ms'  => (int)($o['update_time'] ?? 0),
            ];
        }
        return $events;
    }

    /** Indique si l’ordre est encore "ouvert" côté exchange. */
    public static function isOpenState(int $state): bool
    {
        // La doc expose des états génériques (status_approval=1, status_check=2, status_finish=4).
        // Par prudence, on traite "finish" (4) comme "non ouvert".
        // Si BitMart ajoute d’autres codes, ajuste ici en te basant sur la doc.
        return $state !== 4; // 4 = terminé (filled / canceled / etc. selon sous-états internes)
    }
}
