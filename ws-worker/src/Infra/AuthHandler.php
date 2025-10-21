<?php
namespace App\Infra;

use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AuthHandler
{
    private bool $isAuthenticated = false;
    private int $authRetryCount = 0;
    private int $maxRetries = 3;
    private int $retryDelay = 5; // seconds

    private LoggerInterface $logger;

    public function __construct(
        private BitmartWsClient $wsClient,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function authenticate(): void
    {
        if ($this->isAuthenticated) {
            return;
        }

        if ($this->authRetryCount >= $this->maxRetries) {
            $this->logger->error('Max retry attempts reached. Authentication failed.', ['channel' => 'ws-auth']);
            return;
        }

        $this->authRetryCount++;
        $this->logger->info('Attempting authentication', ['channel' => 'ws-auth', 'attempt' => $this->authRetryCount, 'max' => $this->maxRetries]);
        
        $this->wsClient->authenticate();
        
        // Vérifier l'authentification après un délai
        Loop::addTimer(2, function() {
            if (!$this->wsClient->isAuthenticated()) {
                $this->logger->warning('Authentication failed, retrying', ['channel' => 'ws-auth', 'retry_in_s' => $this->retryDelay]);
                Loop::addTimer($this->retryDelay, function() {
                    $this->authenticate();
                });
            } else {
                $this->isAuthenticated = true;
                $this->logger->info('Successfully authenticated', ['channel' => 'ws-auth']);
            }
        });
    }

    public function isAuthenticated(): bool
    {
        return $this->isAuthenticated && $this->wsClient->isAuthenticated();
    }

    public function reset(): void
    {
        $this->isAuthenticated = false;
        $this->authRetryCount = 0;
    }

    public function onConnectionLost(): void
    {
        $this->reset();
        $this->logger->info('Connection lost, will re-authenticate on reconnect', ['channel' => 'ws-auth']);
    }
}




