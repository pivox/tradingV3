<?php
declare(strict_types=1);

namespace App;

use WebSocket\Client as WsClient;

final class BitmartWsClient
{
    private string $wsBase;
    private string $apiKey;
    private string $secret;
    private ?string $memo;

    public function __construct(string $wsBase, string $apiKey, string $secret, ?string $memo)
    {
        $this->wsBase = $wsBase;
        $this->apiKey = $apiKey;
        $this->secret = $secret;
        $this->memo   = $memo;
    }

    /**
     * Ouvre un WS et retourne le client prêt pour subscribe.
     * La signature/auth pour private channels doit suivre la doc Bitmart.
     */
    public function connect(): WsClient
    {
        // Bitmart supporte permessage-deflate (géré côté lib).
        $client = new WsClient($this->wsBase, [
            'timeout' => 30,
            'headers' => [
                // Ajoute/régénère les entêtes pour auth privée si requis.
            ],
            'context' => stream_context_create([
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
            ]),
        ]);

        return $client;
    }

    public static function subscribePrivate(WsClient $ws, array $channels): void
    {
        foreach ($channels as $ch) {
            $ws->send(json_encode([
                'op'   => 'subscribe',
                'args' => [$ch],
            ], JSON_THROW_ON_ERROR));
        }
    }
}
