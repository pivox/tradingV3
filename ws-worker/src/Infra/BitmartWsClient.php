<?php
namespace App\Infra;

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;

final class BitmartWsClient
{
    private ?WebSocket $conn = null;
    private array $onMessage = [];
    private array $onOpen = [];
    private array $onClose = [];
    private array $onError = [];
    private bool $isAuthenticated = false;

    public function __construct(
        private string $bitmartWsUri,
        private ?string $apiKey = null,
        private ?string $apiSecret = null,
        private ?string $apiMemo = null
    ) {}

    public function connect(): void
    {
        if ($this->conn) return;
        
        (new Connector(Loop::get()))($this->bitmartWsUri)->then(
            function(WebSocket $c){
                $this->conn = $c;
                $this->isAuthenticated = false;
                
                foreach ($this->onOpen as $cb) $cb();
                
                $c->on('message', function($msg) {
                    $message = (string)$msg;
                    $data = json_decode($message, true);
                    
                    // Gestion des réponses d'authentification
                    if (isset($data['action']) && $data['action'] === 'access') {
                        $this->isAuthenticated = $data['success'] ?? false;
                        if (!$this->isAuthenticated) {
                            fwrite(STDERR, "[WS] Authentication failed: " . ($data['error'] ?? 'Unknown error') . "\n");
                        }
                    }
                    
                    // Gestion des erreurs de souscription
                    if (isset($data['success']) && !$data['success']) {
                        fwrite(STDERR, "[WS] Subscription error: " . ($data['error'] ?? 'Unknown error') . "\n");
                    }
                    
                    array_map(fn($cb) => $cb($message), $this->onMessage);
                });
                
                $c->on('close', function() { 
                    $this->conn = null; 
                    $this->isAuthenticated = false;
                    foreach ($this->onClose as $cb) $cb(); 
                });
                
                $c->on('error', function(\Throwable $e) {
                    fwrite(STDERR, "[WS] Connection error: " . $e->getMessage() . "\n");
                    foreach ($this->onError as $cb) $cb($e);
                });
            },
            function(\Throwable $e) {
                fwrite(STDERR, "[WS] Connect error: " . $e->getMessage() . "\n");
                foreach ($this->onError as $cb) $cb($e);
            }
        );
    }

    public function authenticate(): void
    {
        if (!$this->conn || !$this->apiKey || !$this->apiSecret || !$this->apiMemo) {
            fwrite(STDERR, "[WS] Cannot authenticate: missing credentials\n");
            return;
        }

        $timestamp = (string)(time() * 1000);
        $signature = hash_hmac('sha256', $timestamp . '#' . $this->apiMemo . '#bitmart.WebSocket', $this->apiSecret);
        
        $authPayload = [
            'action' => 'access',
            'args' => [$this->apiKey, $timestamp, $signature, 'web']
        ];
        
        $this->send($authPayload);
    }

    public function subscribe(array $channels): void
    {
        if (!$this->conn) {
            fwrite(STDERR, "[WS] Cannot subscribe: not connected\n");
            return;
        }

        $payload = [
            'action' => 'subscribe',
            'args' => $channels
        ];
        
        $this->send($payload);
    }

    public function unsubscribe(array $channels): void
    {
        if (!$this->conn) {
            fwrite(STDERR, "[WS] Cannot unsubscribe: not connected\n");
            return;
        }

        $payload = [
            'action' => 'unsubscribe',
            'args' => $channels
        ];
        
        $this->send($payload);
    }

    public function ping(): void
    {
        if ($this->conn) {
            $this->send(['action' => 'ping']);
        }
    }

    public function isConnected(): bool { return (bool)$this->conn; }
    public function isAuthenticated(): bool { return $this->isAuthenticated; }
    
    public function send(array $payload): void 
    { 
        if ($this->conn) {
            $this->conn->send(json_encode($payload));
        }
    }
    
    public function onMessage(callable $cb): void { $this->onMessage[] = $cb; }
    public function onOpen(callable $cb): void { $this->onOpen[] = $cb; }
    public function onClose(callable $cb): void { $this->onClose[] = $cb; }
    public function onError(callable $cb): void { $this->onError[] = $cb; }
}
