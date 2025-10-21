<?php
namespace App\Infra;

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BitmartWsClient
{
    private ?WebSocket $conn = null;
    private array $onMessage = [];
    private array $onOpen = [];
    private array $onClose = [];
    private array $onError = [];
    private bool $isAuthenticated = false;

    private LoggerInterface $logger;

    public function __construct(
        private string $bitmartWsUri,
        private ?string $apiKey = null,
        private ?string $apiSecret = null,
        private ?string $apiMemo = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

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
                            $this->logger->error('WS Authentication failed', ['channel' => 'ws-client', 'error' => $data['error'] ?? 'Unknown error']);
                        }
                    }
                    
                    // Gestion des erreurs de souscription
                    if (isset($data['success']) && !$data['success']) {
                        $this->logger->error('WS Subscription error', ['channel' => 'ws-client', 'error' => $data['error'] ?? 'Unknown error']);
                    }
                    
                    array_map(fn($cb) => $cb($message), $this->onMessage);
                });
                
                $c->on('close', function() { 
                    $this->conn = null; 
                    $this->isAuthenticated = false;
                    foreach ($this->onClose as $cb) $cb(); 
                });
                
                $c->on('error', function(\Throwable $e) {
                    $this->logger->error('WS Connection error', ['channel' => 'ws-client', 'error' => $e->getMessage()]);
                    foreach ($this->onError as $cb) $cb($e);
                });
            },
            function(\Throwable $e) {
                $this->logger->error('WS Connect error', ['channel' => 'ws-client', 'error' => $e->getMessage()]);
                foreach ($this->onError as $cb) $cb($e);
            }
        );
    }

    public function authenticate(): void
    {
        if (!$this->conn || !$this->apiKey || !$this->apiSecret || !$this->apiMemo) {
            $this->logger->warning('Cannot authenticate: missing credentials or connection', ['channel' => 'ws-client']);
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
            $this->logger->warning('Cannot subscribe: not connected', ['channel' => 'ws-client']);
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
            $this->logger->warning('Cannot unsubscribe: not connected', ['channel' => 'ws-client']);
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
