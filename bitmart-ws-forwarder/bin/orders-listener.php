#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use App\{HttpClient, BitmartWsClient, OrdersWorker};

$env = fn(string $k, ?string $d=null) => $_SERVER[$k] ?? getenv($k) ?: $d;

$wsBase   = $env('BITMART_WS_BASE', 'wss://ws-manager-compress.bitmart.com?protocol=1.1');
$apiKey   = (string)$env('BITMART_API_KEY', '');
$secret   = (string)$env('BITMART_SECRET_KEY', '');
$memo     = $env('BITMART_MEMO');

$baseUrl  = rtrim((string)$env('SYMFONY_BASE_URL', 'http://localhost:9000'), '/');
$endpoint = (string)$env('SYMFONY_ORDERS_ENDPOINT', '/api/orders/events');
$token    = (string)$env('SYMFONY_WEBHOOK_TOKEN', '');

$httpTimeout     = (int)$env('HTTP_TIMEOUT', '5');
$reconnectBaseMs = (int)$env('RECONNECT_BASE_SLEEP_MS', '1000');
$reconnectMaxMs  = (int)$env('RECONNECT_MAX_SLEEP_MS', '10000');

$ws  = new BitmartWsClient($wsBase, $apiKey, $secret, $memo);
$http= new HttpClient($baseUrl, $token, $httpTimeout);

$worker = new OrdersWorker($ws, $http, $endpoint, $reconnectBaseMs, $reconnectMaxMs);
$worker->run();
