<?php
declare(strict_types=1);

namespace App;

use Throwable;
use WebSocket\ConnectionException;

final class OrdersWorker
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

                BitmartWsClient::subscribePrivate($ws, ['futures/order']); // canal privé ordres

                while (true) {
                    $raw = $ws->receive();
                    if (!$raw) { continue; }

                    $msg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                    if (($msg['table'] ?? '') === 'futures/order' || ($msg['topic'] ?? '') === 'futures/order') {
                        $payload = [
                            'received_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                            'data'        => $msg['data'] ?? $msg,
                            'origin'      => 'bitmart.ws',
                            'channel'     => 'futures/order',
                        ];
                        $this->http->postJson($this->endpointPath, $payload);
                    }
                }
            } catch (ConnectionException|Throwable $e) {
                fwrite(STDERR, "[orders] error: {$e->Message()}\n");
                usleep($sleep * 1000);
                $sleep = min($sleep * 2, $this->reconnectMaxMs);
                continue;
            } finally {
                $sleep = $this->reconnectBaseMs;
            }
        }
    }
}
