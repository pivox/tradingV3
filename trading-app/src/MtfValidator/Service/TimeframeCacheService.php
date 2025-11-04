<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Entity\ValidationCache;
use App\MtfValidator\Support\KlineTimeParser;
use App\Repository\ValidationCacheRepository;
use App\Runtime\Cache\DbValidationCache;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TimeframeCacheService
{
    public function __construct(
        private readonly ValidationCacheRepository $validationCacheRepository,
        private readonly KlineTimeParser $klineTimeParser,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
        private readonly ?DbValidationCache $dbValidationCache = null,
    ) {
    }

    public function getCachedResult(string $symbol, string $timeframe, ?bool &$hadRecord = null): ?array
    {
        $hadRecord = null;
        try {
            $cacheKey = $this->buildCacheKey($symbol, $timeframe);
            $record = $this->validationCacheRepository->findByCacheKey($cacheKey);
            if ($record === null) {
                $hadRecord = false;
                return null;
            }
            $hadRecord = true;
            if ($record->isExpired()) {
                return null;
            }

            $payload = $record->getPayload();
            return [
                'status' => $payload['status'] ?? 'INVALID',
                'signal_side' => $payload['signal_side'] ?? 'NONE',
                'kline_time' => $this->klineTimeParser->parse($payload['kline_time'] ?? null),
                'from_cache' => true,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('[MTF] Cache read failed', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function shouldReuseCachedResult(?array $cached, string $timeframe, string $symbol): bool
    {
        if (!is_array($cached)) {
            return false;
        }

        $klineTime = $cached['kline_time'] ?? null;
        if (!$klineTime instanceof \DateTimeImmutable) {
            return false;
        }

        $expiresAt = match ($timeframe) {
            '4h' => $klineTime->add(new \DateInterval('PT4H')),
            '1h' => $klineTime->add(new \DateInterval('PT1H')),
            '15m' => $klineTime->add(new \DateInterval('PT15M')),
            '5m' => $klineTime->add(new \DateInterval('PT5M')),
            '1m' => $klineTime->add(new \DateInterval('PT1M')),
            default => throw new \InvalidArgumentException('Invalid timeframe'),
        };

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        if ($now >= $expiresAt) {
            $cacheKey = $this->buildCacheKey($symbol, $timeframe);
            $this->validationCacheRepository->invalidateCache($cacheKey);
            $this->dbValidationCache?->delete($cacheKey);
            return false;
        }

        $status = strtoupper((string) ($cached['status'] ?? ''));
        return $status === 'VALID';
    }

    public function storeResult(string $symbol, string $timeframe, array $result): void
    {
        if (strtoupper((string) ($result['status'] ?? '')) === 'GRACE_WINDOW') {
            $this->logger->debug('[MTF] Skip cache write for grace window result', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ]);
            return;
        }

        try {
            $cacheKey = $this->buildCacheKey($symbol, $timeframe);
            $record = $this->validationCacheRepository->findByCacheKey($cacheKey) ?? new ValidationCache();
            $parsed = $this->klineTimeParser->parse($result['kline_time'] ?? null);
            $record
                ->setCacheKey($cacheKey)
                ->setPayload([
                    'status' => $result['status'] ?? 'INVALID',
                    'signal_side' => $result['signal_side'] ?? 'NONE',
                    'kline_time' => $parsed instanceof \DateTimeImmutable ? $parsed->format('Y-m-d H:i:s') : null,
                ])
                ->setExpiresAt($this->computeExpiresAt($timeframe));

            $this->validationCacheRepository->upsert($record);
        } catch (\Throwable $e) {
            $this->logger->debug('[MTF] Cache write failed', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildCacheKey(string $symbol, string $timeframe): string
    {
        return sprintf('mtf_tf_state_%s_%s', strtoupper($symbol), strtolower($timeframe));
    }

    private function computeExpiresAt(string $timeframe): \DateTimeImmutable
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $minute = (int) $now->format('i');
        $hour = (int) $now->format('H');

        return match ($timeframe) {
            '4h' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setTime($hour - ($hour % 4), 0, 0)
                ->modify('+4 hours')
                ->modify('-1 second'),
            '1h' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setTime($hour, 0, 0)
                ->modify('+1 hour')
                ->modify('-1 second'),
            '15m' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setTime($hour, $minute - ($minute % 15), 0)
                ->modify('+15 minutes')
                ->modify('-1 second'),
            '5m' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setTime($hour, $minute - ($minute % 5), 0)
                ->modify('+5 minutes')
                ->modify('-1 second'),
            '1m' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setTime($hour, $minute, 0)
                ->modify('+1 minute')
                ->modify('-1 second'),
            default => throw new \InvalidArgumentException('Invalid timeframe'),
        };
    }
}
