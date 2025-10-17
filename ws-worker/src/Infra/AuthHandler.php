<?php
namespace App\Infra;

use React\EventLoop\Loop;

final class AuthHandler
{
    private bool $isAuthenticated = false;
    private int $authRetryCount = 0;
    private int $maxRetries = 3;
    private int $retryDelay = 5; // seconds

    public function __construct(
        private BitmartWsClient $wsClient
    ) {}

    public function authenticate(): void
    {
        if ($this->isAuthenticated) {
            return;
        }

        if ($this->authRetryCount >= $this->maxRetries) {
            fwrite(STDERR, "[AUTH] Max retry attempts reached. Authentication failed.\n");
            return;
        }

        $this->authRetryCount++;
        fwrite(STDOUT, "[AUTH] Attempting authentication (attempt {$this->authRetryCount}/{$this->maxRetries})...\n");
        
        $this->wsClient->authenticate();
        
        // Vérifier l'authentification après un délai
        Loop::addTimer(2, function() {
            if (!$this->wsClient->isAuthenticated()) {
                fwrite(STDERR, "[AUTH] Authentication failed, retrying in {$this->retryDelay} seconds...\n");
                Loop::addTimer($this->retryDelay, function() {
                    $this->authenticate();
                });
            } else {
                $this->isAuthenticated = true;
                fwrite(STDOUT, "[AUTH] Successfully authenticated!\n");
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
        fwrite(STDOUT, "[AUTH] Connection lost, will re-authenticate on reconnect\n");
    }
}





