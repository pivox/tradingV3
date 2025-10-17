<?php
declare(strict_types=1);

namespace App\Bitmart\Ws;

use Psr\Log\LoggerInterface;

/**
 * Écoute les positions Futures via le canal privé "futures/position".
 * Pousse ~toutes les 10s + sur changement. Filtre simple: on garde les positions ouvertes (hold_volume > 0).
 */
final class PositionStream
{
    public function __construct(
        private readonly PrivateWsClient $wsClient,
        private readonly LoggerInterface $logger,
    ) {}

    /** Lance l’écoute. */
    public function listen(callable $onPositions): void
    {
        // Enregistrer le handler de groupe AVANT de se connecter
        $this->wsClient->onGroup('futures/position', function (array $payload) use ($onPositions) {
            try {
                $open = self::normalize($payload);
            } catch (\Throwable $e) {
                $this->logger->error('[PositionStream] normalize failed', ['error' => $e->getMessage()]);
                $open = [];
            }
            // Appeler le callback même si vide pour permettre la détection des fermetures
            $onPositions($open);
        });

        // Démarre la connexion + login + subscribe.
        $this->wsClient->run(['futures/position']);
    }

    /** Exemple de normalisation d’un message WS "futures/position" */
    public static function normalize(array $wsPayload): array
    {
        // Format attendu (doc) :
        // { "group":"futures/position", "data":[ { symbol, hold_volume, position_type, ... } ] }
        $rows = (array)($wsPayload['data'] ?? []);
        $open = [];
        foreach ($rows as $p) {
            // On ne garde que les positions "ouvertes" (volume > 0), sinon ignorer.
            $hold = (float)($p['hold_volume'] ?? 0);
            if ($hold <= 0) {
                continue;
            }
            $open[] = [
                'symbol'         => (string)($p['symbol'] ?? ''),
                'hold_volume'    => $hold,
                'side'           => (int)($p['position_type'] ?? 0),   // 1=long, 2=short
                'open_type'      => (int)($p['open_type'] ?? 0),       // 1=isolated, 2=cross
                'avg_open_price' => (float)($p['open_avg_price'] ?? 0),
                'liq_price'      => isset($p['liquidate_price']) ? (float)$p['liquidate_price'] : null,
                'update_time_ms' => (int)($p['update_time'] ?? 0),
                'position_mode'  => (string)($p['position_mode'] ?? ''),// hedge_mode | one_way_mode
            ];
        }
        return $open;
    }
}
