<?php
declare(strict_types=1);

namespace App;

use Throwable;
use WebSocket\ConnectionException;

final class PositionsWorker
{
    public function __construct(
        private BitmartWsClient $wsClient,
        private HttpClient $http,
        private string $endpointPath,
        private int $reconnectBaseMs,
        private int $reconnectMaxMs
    ) {}

    public function run(): void
    {
        $sleep = $this->reconnectBaseMs;
        while (true) {
            try {
                $ws = $this->wsClient->connect();

                // 1) login/auth si nécessaire (privé), puis subscribe :
                BitmartWsClient::subscribePrivate($ws, ['futures/position']); // canal privé positions

                // 2) Boucle réception
                while (true) {
                    $raw = $ws->receive();
                    if (!$raw) { continue; }

                    $msg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                    // Filtrage/normalisation rapide (adapte la structure d'après payload Bitmart)
                    if (($msg['table'] ?? '') === 'futures/position' || ($msg['topic'] ?? '') === 'futures/position') {
                        $payload = [
                            'received_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                            'data'        => $msg['data'] ?? $msg,
                            'origin'      => 'bitmart.ws',
                            'channel'     => 'futures/position',
                        ];
                        $this->http->postJson($this->endpointPath, $payload);
                    }
                }
            } catch (ConnectionException|Throwable $e) {
                fwrite(STDERR, "[positions] error: {$e->getMessage()}\n");
                usleep($sleep * 1000);
                $sleep = min($sleep * 2, $this->reconnectMaxMs); // backoff
                continue;
            } finally {
                $sleep = $this->reconnectBaseMs; // reset après succès soutenu
            }
        }
    }
}
