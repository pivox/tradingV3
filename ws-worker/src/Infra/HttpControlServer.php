<?php
namespace App\Infra;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Socket\SocketServer;

final class HttpControlServer
{
    private HttpServer $server;
    private SocketServer $socket;

    public function __construct(
        private \App\Worker\MainWorker $mainWorker,
        string $ctrlAddress
    ){
        $this->server = new HttpServer(function (ServerRequestInterface $req) {
            return $this->handleRequest($req);
        });

        $this->socket = new SocketServer($ctrlAddress);
        $this->server->listen($this->socket);
        
        fwrite(STDOUT, "[HTTP] Control server listening on {$ctrlAddress}\n");
    }

    private function handleRequest(ServerRequestInterface $req): \React\Http\Message\Response
    {
        $method = $req->getMethod();
        $path = $req->getUri()->getPath();
        $data = json_decode((string)$req->getBody(), true) ?? [];

        // Endpoint pour obtenir le statut
        if ($method === 'GET' && $path === '/status') {
            return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode($this->mainWorker->getStatus()));
        }

        // Endpoints pour les klines
        if ($method === 'POST' && $path === '/subscribe') {
            return $this->handleKlineSubscribe($data);
        }

        if ($method === 'POST' && $path === '/unsubscribe') {
            return $this->handleKlineUnsubscribe($data);
        }

        // Endpoints pour les klines (anciens endpoints pour compatibilité)
        if ($method === 'POST' && $path === '/klines/subscribe') {
            return $this->handleKlineSubscribe($data);
        }

        if ($method === 'POST' && $path === '/klines/unsubscribe') {
            return $this->handleKlineUnsubscribe($data);
        }

        // Endpoints pour les ordres
        if ($method === 'POST' && $path === '/orders/subscribe') {
            $this->mainWorker->subscribeToOrders();
            return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true, 'message' => 'Subscribed to orders']));
        }

        if ($method === 'POST' && $path === '/orders/unsubscribe') {
            $this->mainWorker->unsubscribeFromOrders();
            return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true, 'message' => 'Unsubscribed from orders']));
        }

        // Endpoints pour les positions
        if ($method === 'POST' && $path === '/positions/subscribe') {
            $this->mainWorker->subscribeToPositions();
            return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true, 'message' => 'Subscribed to positions']));
        }

        if ($method === 'POST' && $path === '/positions/unsubscribe') {
            $this->mainWorker->unsubscribeFromPositions();
            return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true, 'message' => 'Unsubscribed from positions']));
        }

        // Endpoint pour arrêter le worker
        if ($method === 'POST' && $path === '/stop') {
            $this->mainWorker->stop();
            return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true, 'message' => 'Worker stopped']));
        }

        // Endpoint pour obtenir l'aide
        if ($method === 'GET' && $path === '/help') {
            return $this->getHelpResponse();
        }

        return new \React\Http\Message\Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
    }

    private function handleKlineSubscribe(array $data): \React\Http\Message\Response
    {
        $symbol = $data['symbol'] ?? null;
        $tfs = isset($data['tfs']) ? (array)$data['tfs'] : null;

        if (!$symbol || !$tfs) {
        return new \React\Http\Message\Response(400, ['Content-Type' => 'application/json'], json_encode([
            'ok' => false, 
            'error' => 'Missing symbol or tfs parameter'
        ]));
        }

        $this->mainWorker->subscribeToKlines($symbol, $tfs);
        return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode([
            'ok' => true, 
            'message' => "Subscribed to klines for {$symbol} with timeframes: " . implode(', ', $tfs)
        ]));
    }

    private function handleKlineUnsubscribe(array $data): \React\Http\Message\Response
    {
        $symbol = $data['symbol'] ?? null;
        $tfs = isset($data['tfs']) ? (array)$data['tfs'] : null;

        if (!$symbol || !$tfs) {
        return new \React\Http\Message\Response(400, ['Content-Type' => 'application/json'], json_encode([
            'ok' => false, 
            'error' => 'Missing symbol or tfs parameter'
        ]));
        }

        $this->mainWorker->unsubscribeFromKlines($symbol, $tfs);
        return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode([
            'ok' => true, 
            'message' => "Unsubscribed from klines for {$symbol} with timeframes: " . implode(', ', $tfs)
        ]));
    }

    private function getHelpResponse(): \React\Http\Message\Response
    {
        $help = [
            'endpoints' => [
                'GET /status' => 'Get worker status',
                'GET /help' => 'Show this help',
                'POST /klines/subscribe' => 'Subscribe to klines (body: {"symbol": "BTCUSDT", "tfs": ["1m", "5m"]})',
                'POST /klines/unsubscribe' => 'Unsubscribe from klines (body: {"symbol": "BTCUSDT", "tfs": ["1m", "5m"]})',
                'POST /orders/subscribe' => 'Subscribe to orders',
                'POST /orders/unsubscribe' => 'Unsubscribe from orders',
                'POST /positions/subscribe' => 'Subscribe to positions',
                'POST /positions/unsubscribe' => 'Unsubscribe from positions',
                'POST /stop' => 'Stop the worker'
            ],
            'supported_timeframes' => ['1m', '5m', '15m', '30m', '1h', '2h', '4h', '1d', '1w'],
            'example_requests' => [
                'curl -X POST http://localhost:8089/klines/subscribe -H "Content-Type: application/json" -d \'{"symbol": "BTCUSDT", "tfs": ["1m", "5m"]}\'',
                'curl -X POST http://localhost:8089/orders/subscribe',
                'curl -X POST http://localhost:8089/positions/subscribe',
                'curl http://localhost:8089/status'
            ]
        ];

        return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode($help));
    }
}
