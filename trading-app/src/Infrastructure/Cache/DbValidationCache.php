<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Domain\Common\Dto\ValidationStateDto;
use App\Domain\Ports\Out\ValidationCachePort;
use App\Repository\ValidationCacheRepository;
use App\Service\TradingConfigService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class DbValidationCache implements ValidationCachePort
{
    public function __construct(
        private readonly ValidationCacheRepository $validationRepository,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly TradingConfigService $tradingConfigService,
    ) {
    }

    /**
     * Met en cache un état de validation
     */
    public function cacheValidationState(ValidationStateDto $state): void
    {
        try {
            // Récupérer la version actuelle de la configuration
            $version = $this->tradingConfigService->getVersion();
            
            $cache = new \App\Entity\ValidationCache();
            $cache
                ->setCacheKey($state->getCacheKeyWithVersion($version))
                ->setPayload([
                    'symbol' => $state->symbol,
                    'timeframe' => $state->timeframe->value,
                    'status' => $state->status,
                    'kline_time' => $state->klineTime->format('Y-m-d H:i:s'),
                    'details' => $state->details,
                    'cached_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                    'validation_hash' => md5(serialize($state->toArray())),
                    'config_version' => $version
                ])
                ->setExpiresAt($state->expiresAt);

            $this->validationRepository->upsert($cache);
            
            $this->logger->info('Validation state cached', [
                'cache_key' => $state->getCacheKeyWithVersion($version),
                'symbol' => $state->symbol,
                'timeframe' => $state->timeframe->value,
                'status' => $state->status,
                'config_version' => $version,
                'expires_at' => $state->expiresAt->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cache validation state', [
                'cache_key' => $state->getCacheKey(),
                'symbol' => $state->symbol,
                'timeframe' => $state->timeframe->value,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Récupère un état de validation depuis le cache
     */
    public function getValidationState(string $cacheKey): ?ValidationStateDto
    {
        $cache = $this->validationRepository->findByCacheKey($cacheKey);
        
        if (!$cache || $cache->isExpired()) {
            return null;
        }

        $payload = $cache->getPayload();
        
        return new ValidationStateDto(
            symbol: $payload['symbol'] ?? '',
            timeframe: \App\Domain\Common\Enum\Timeframe::from($payload['timeframe'] ?? '1m'),
            status: $payload['status'] ?? 'INVALID',
            klineTime: new \DateTimeImmutable($payload['kline_time'] ?? 'now'),
            expiresAt: $cache->getExpiresAt(),
            details: $payload['details'] ?? []
        );
    }

    /**
     * Vérifie si un état de validation est en cache et valide
     */
    public function isValidationCached(string $cacheKey): bool
    {
        return $this->validationRepository->isCacheValid($cacheKey);
    }

    /**
     * Invalide un état de validation
     */
    public function invalidateValidation(string $cacheKey): void
    {
        $this->validationRepository->invalidateCache($cacheKey);
    }

    /**
     * Purge le cache de validation expiré
     */
    public function purgeExpiredValidations(): int
    {
        return $this->validationRepository->deleteExpiredCaches();
    }

    /**
     * Récupère tous les états de validation pour un symbole
     */
    public function getValidationStates(string $symbol): array
    {
        $caches = $this->validationRepository->findBySymbol($symbol);
        
        return array_map(function ($cache) {
            $payload = $cache->getPayload();
            
            return new ValidationStateDto(
                symbol: $payload['symbol'] ?? '',
                timeframe: \App\Domain\Common\Enum\Timeframe::from($payload['timeframe'] ?? '1m'),
                status: $payload['status'] ?? 'INVALID',
                klineTime: new \DateTimeImmutable($payload['kline_time'] ?? 'now'),
                expiresAt: $cache->getExpiresAt(),
                details: $payload['details'] ?? []
            );
        }, $caches);
    }

    /**
     * Cache un état de validation MTF avec expiration automatique
     */
    public function cacheMtfValidation(
        string $symbol,
        \App\Domain\Common\Enum\Timeframe $timeframe,
        \DateTimeImmutable $klineTime,
        string $status,
        array $details = [],
        int $expirationMinutes = 5
    ): void {
        $expiresAt = $this->clock->now()->modify("+$expirationMinutes minutes");
        
        $state = new ValidationStateDto(
            symbol: $symbol,
            timeframe: $timeframe,
            status: $status,
            klineTime: $klineTime,
            expiresAt: $expiresAt,
            details: array_merge($details, [
                'mtf_validation' => true,
                'cached_by' => 'mtf_system'
            ])
        );

        $this->cacheValidationState($state);
    }

    /**
     * Génère une clé de cache pour une validation MTF
     */
    public function generateMtfCacheKey(
        string $symbol,
        \App\Domain\Common\Enum\Timeframe $timeframe,
        \DateTimeImmutable $klineTime
    ): string {
        return sprintf(
            'mtf_validation_%s_%s_%s',
            strtoupper($symbol),
            $timeframe->value,
            $klineTime->format('Y-m-d_H-i-s')
        );
    }
}

