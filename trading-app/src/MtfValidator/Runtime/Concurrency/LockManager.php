<?php

declare(strict_types=1);

namespace App\MtfValidator\Runtime\Concurrency;

use App\Contract\Runtime\LockManagerInterface;
use App\Contract\Runtime\Dto\LockInfoDto;
use App\MtfValidator\Runtime\Concurrency\Dto\LockResultDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Gestionnaire de verrous pour la concurrence
 * Utilise Redis pour implémenter des verrous distribués
 */
#[AsAlias(id: LockManagerInterface::class)]
class LockManager implements LockManagerInterface
{
    private const DEFAULT_TIMEOUT = 30; // 30 secondes
    private const DEFAULT_RETRY_DELAY = 100; // 100ms

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Acquiert un verrou avec timeout
     */
    public function acquireLock(string $key, int $timeout = self::DEFAULT_TIMEOUT): bool
    {
        $lockKey = "lock:{$key}";
        $identifier = uniqid(gethostname() . '_', true);
        $expireTime = time() + $timeout;

        $result = $this->redis->set($lockKey, $identifier, ['NX', 'EX' => $timeout]);

        if ($result) {
            $this->logger->info("Verrou acquis", [
                'key' => $key,
                'identifier' => $identifier,
                'timeout' => $timeout
            ]);
            return true;
        }

        $this->logger->warning("Impossible d'acquérir le verrou", [
            'key' => $key,
            'timeout' => $timeout
        ]);

        return false;
    }

    /**
     * Acquiert un verrou avec retry automatique
     */
    public function acquireLockWithRetry(
        string $key, 
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = 3,
        int $retryDelay = self::DEFAULT_RETRY_DELAY
    ): bool {
        for ($i = 0; $i < $maxRetries; $i++) {
            if ($this->acquireLock($key, $timeout)) {
                return true;
            }

            if ($i < $maxRetries - 1) {
                usleep($retryDelay * 1000); // Convertir en microsecondes
            }
        }

        return false;
    }

    /**
     * Libère un verrou
     */
    public function releaseLock(string $key): bool
    {
        $lockKey = "lock:{$key}";
        
        $script = "
            if redis.call('get', KEYS[1]) == ARGV[1] then
                return redis.call('del', KEYS[1])
            else
                return 0
            end
        ";

        $identifier = $this->redis->get($lockKey);
        $result = $this->redis->eval($script, [$lockKey, $identifier], 1);

        if ($result) {
            $this->logger->info("Verrou libéré", ['key' => $key]);
            return true;
        }

        $this->logger->warning("Impossible de libérer le verrou", ['key' => $key]);
        return false;
    }

    /**
     * Vérifie si un verrou existe
     */
    public function isLocked(string $key): bool
    {
        $lockKey = "lock:{$key}";
        return $this->redis->exists($lockKey) > 0;
    }

    /**
     * Récupère les informations d'un verrou
     */
    public function getLockInfo(string $key): ?LockInfoDto
    {
        $lockKey = "lock:{$key}";
        
        if (!$this->redis->exists($lockKey)) {
            return null;
        }

        $ttl = $this->redis->ttl($lockKey);
        $identifier = $this->redis->get($lockKey);

        return new LockInfoDto(
            key: $key,
            identifier: $identifier,
            ttl: $ttl,
            expiresAt: time() + $ttl,
            createdAt: new \DateTimeImmutable()
        );
    }

    /**
     * Force la libération d'un verrou (utilisé avec précaution)
     */
    public function forceReleaseLock(string $key): bool
    {
        $lockKey = "lock:{$key}";
        $result = $this->redis->del($lockKey);
        
        if ($result) {
            $this->logger->warning("Verrou forcé libéré", ['key' => $key]);
            return true;
        }

        return false;
    }

    /**
     * Récupère tous les verrous actifs
     */
    public function getAllLocks(): array
    {
        $pattern = "lock:*";
        $keys = $this->redis->keys($pattern);
        $locks = [];

        foreach ($keys as $key) {
            $lockKey = str_replace('lock:', '', $key);
            $info = $this->getLockInfo($lockKey);
            if ($info) {
                $locks[] = $info->toArray();
            }
        }

        return $locks;
    }

    /**
     * Nettoie les verrous expirés
     */
    public function cleanupExpiredLocks(): int
    {
        $locks = $this->getAllLocks();
        $cleaned = 0;

        foreach ($locks as $lock) {
            if ($lock['ttl'] <= 0) {
                if ($this->forceReleaseLock($lock['key'])) {
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }
}
