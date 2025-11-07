<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Common\Enum\Timeframe;
use App\Config\MtfConfigProviderInterface;
use App\Config\MtfValidationConfig;
use App\Contract\MtfValidator\Dto\ValidationContextDto;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\MtfValidator\Entity\MtfAudit;
use App\MtfValidator\Event\MtfAuditEvent;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\Provider\Bitmart\Service\KlineJsonIngestionService;
use App\Provider\Repository\ContractRepository;
use App\Provider\Repository\KlineRepository;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\Repository\MtfStateRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Entity\ValidationCache as ValidationCacheEntity;
use App\Runtime\Cache\DbValidationCache;
use App\Entity\IndicatorSnapshot;
use App\Repository\IndicatorSnapshotRepository;
use App\Contract\Signal\SignalValidationServiceInterface;
use App\MtfValidator\Service\Timeframe\Timeframe4hService;
use App\MtfValidator\Service\Timeframe\Timeframe1hService;
use App\MtfValidator\Service\Timeframe\Timeframe15mService;
use App\MtfValidator\Service\Timeframe\Timeframe5mService;
use App\MtfValidator\Service\Timeframe\Timeframe1mService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class MtfService
{
    public function __construct(
        private readonly MtfTimeService $timeService,
        private readonly KlineRepository $klineRepository,
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfAuditRepository $mtfAuditRepository,
        private readonly ContractRepository $contractRepository,
        private readonly SignalValidationServiceInterface $signalValidationService,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsFlowLogger,
        private readonly MtfConfigProviderInterface $mtfConfig,
        private readonly MtfValidationConfig $mtfValidationConfig,
        private readonly BitmartHttpClientPublic $bitmartClient,
        private readonly KlineProviderInterface $klineProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Timeframe4hService $timeframe4hService,
        private readonly Timeframe1hService $timeframe1hService,
        private readonly Timeframe15mService $timeframe15mService,
        private readonly Timeframe5mService $timeframe5mService,
        private readonly Timeframe1mService $timeframe1mService,
        private readonly ?DbValidationCache $validationCache = null,
        private readonly ?KlineJsonIngestionService $klineJsonIngestion = null,
    ) {
    }

    /**
     * Parse various kline_time representations into a UTC DateTimeImmutable.
     * Accepts DateTimeInterface, numeric timestamps (s/ms), or common date strings.
     */
    private function parseKlineTime(mixed $raw): ?\DateTimeImmutable
    {
        try {
            if ($raw instanceof \DateTimeImmutable) {
                return $raw->setTimezone(new \DateTimeZone('UTC'));
            }
            if ($raw instanceof \DateTimeInterface) {
                return (new \DateTimeImmutable($raw->format('Y-m-d H:i:s'), $raw->getTimezone()))
                    ->setTimezone(new \DateTimeZone('UTC'));
            }
            // Numeric timestamp (seconds or milliseconds)
            if (is_int($raw) || is_float($raw) || (is_string($raw) && ctype_digit($raw))) {
                $num = (int) $raw;
                if ($num > 2000000000) { // likely milliseconds
                    $num = intdiv($num, 1000);
                }
                $dt = (new \DateTimeImmutable('@' . $num))->setTimezone(new \DateTimeZone('UTC'));
                return $dt;
            }
            if (is_string($raw) && $raw !== '') {
                // Try native parser first
                try {
                    return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))
                        ->setTimezone(new \DateTimeZone('UTC'));
                } catch (\Throwable) {
                    // Try explicit common format
                    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
                    if ($dt instanceof \DateTimeImmutable) {
                        return $dt->setTimezone(new \DateTimeZone('UTC'));
                    }
                }
            }
        } catch (\Throwable) {
            // fallthrough
        }
        return null;
    }

    private function buildTfCacheKey(string $symbol, string $tf): string
    {
        return sprintf('mtf_tf_state_%s_%s', strtoupper($symbol), strtolower($tf));
    }

    private function computeTfExpiresAt(string $tf): \DateTimeImmutable
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $minute = (int)$now->format('i');
        $hour = (int)$now->format('H');
        $second = 0;
        switch ($tf) {
            case '4h':
                $baseHour = $hour - ($hour % 4);
                $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->setTime($baseHour, 0, 0)
                    ->modify('+4 hours');
                return $next->modify('-1 second');
            case '1h':
                $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->setTime($hour, 0, 0)
                    ->modify('+1 hour');
                return $next->modify('-1 second');
            case '15m':
                $baseMin = $minute - ($minute % 15);
                $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->setTime($hour, $baseMin, 0)
                    ->modify('+15 minutes');
                return $next->modify('-1 second');
            case '5m':
                $baseMin = $minute - ($minute % 5);
                $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->setTime($hour, $baseMin, 0)
                    ->modify('+5 minutes');
                return $next->modify('-1 second');
            case '1m':
            default:
                $next = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->setTime($hour, $minute, $second)
                    ->modify('+1 minute');
                return $next->modify('-1 second');
        }
    }

    /**
         * Decide whether a cached timeframe result can be reused as-is.
     */
    private function shouldReuseCachedResult(?array $cached, string $timeframe, string $symbol): bool
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $expiredAT = $array['kline_time'] ?? null;
        if (!$expiredAT instanceof \DateTimeImmutable) {
            return false;
        }
        $addInterval = match ($timeframe) {
            '4h' => new \DateInterval('PT4H'),
            '1h' => new \DateInterval('PT1H'),
            '15m' => new \DateInterval('PT15M'),
            '5m' => new \DateInterval('PT5M'),
            '1m' => new \DateInterval('PT1M'),
            default => throw new \InvalidArgumentException('Invalid timeframe'),
        };
        $expiresAt = $expiredAT->add($addInterval);
        if ($now >= $expiresAt) {
            $cacheKey = $this->buildTfCacheKey($symbol, $timeframe);
            $this->validationCache->delete($cacheKey);
            return false;
        }
        if (!is_array($cached)) {
            return false;
        }

        $status = strtoupper((string)($cached['status'] ?? ''));
        return $status === 'VALID';
    }

    private function getCachedTfResult(string $symbol, string $tf, ?bool &$hadRecord = null): ?array
    {
        $hadRecord = null;
        try {
            $repo = $this->entityManager->getRepository(ValidationCacheEntity::class);
            $cacheKey = $this->buildTfCacheKey($symbol, $tf);
            /** @var ValidationCacheEntity|null $rec */
            $rec = $repo->findOneBy(['cacheKey' => $cacheKey]);
            if ($rec === null) {
                $hadRecord = false;
                return null;
            }
            $hadRecord = true;
            if ($rec->isExpired()) {
                return null;
            }
            $payload = $rec->getPayload();
            return [
                'status' => $payload['status'] ?? 'INVALID',
                'signal_side' => $payload['signal_side'] ?? 'NONE',
                'kline_time' => isset($payload['kline_time']) ? new \DateTimeImmutable($payload['kline_time'], new \DateTimeZone('UTC')) : null,
                'from_cache' => true,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('[MTF] Cache read failed', ['symbol' => $symbol, 'tf' => $tf, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function putCachedTfResult(string $symbol, string $tf, array $result): void
    {
        if ($this->isGraceWindowResult($result)) {
            $this->logger->debug('[MTF] Skip cache write for grace window result', ['symbol' => $symbol, 'tf' => $tf]);
            return;
        }
        try {
            $repo = $this->entityManager->getRepository(ValidationCacheEntity::class);
            $cacheKey = $this->buildTfCacheKey($symbol, $tf);
            $rec = $repo->findOneBy(['cacheKey' => $cacheKey]) ?? new ValidationCacheEntity();
            $klineIso = null;
            $parsed = $this->parseKlineTime($result['kline_time'] ?? null);
            if ($parsed instanceof \DateTimeImmutable) {
                $klineIso = $parsed->format('Y-m-d H:i:s');
            }
            $rec->setCacheKey($cacheKey)
                ->setPayload([
                    'status' => $result['status'] ?? 'INVALID',
                    'signal_side' => $result['signal_side'] ?? 'NONE',
                    'kline_time' => $klineIso,
                ])
                ->setExpiresAt($this->computeTfExpiresAt($tf));
            $this->entityManager->persist($rec);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->debug('[MTF] Cache write failed', ['symbol' => $symbol, 'tf' => $tf, 'error' => $e->getMessage()]);
        }
    }

    private function persistIndicatorSnapshot(string $symbol, string $tf, array $result): void
    {
        if ($this->isGraceWindowResult($result)) {
            return;
        }
        try {
            $repo = $this->entityManager->getRepository(IndicatorSnapshot::class);
            if (!$repo instanceof IndicatorSnapshotRepository) {
                return;
            }
            $klineTime = $this->parseKlineTime($result['kline_time'] ?? null);
            if (!$klineTime instanceof \DateTimeImmutable) {
                // No valid kline time, skip persistence to avoid corrupt records
                return;
            }

            $values = [];
            // Extraire et aplatir un sous-ensemble du contexte indicateur pour éviter les collisions de clés typées
            $context = $result['indicator_context'] ?? [];
            if (is_array($context)) {
                // RSI
                if (isset($context['rsi']) && is_numeric($context['rsi'])) {
                    $values['rsi'] = (float)$context['rsi'];
                }
                // ATR (du contexte)
                if (isset($context['atr']) && is_numeric($context['atr'])) {
                    $values['atr'] = (string)$context['atr'];
                }
                // VWAP
                if (isset($context['vwap']) && is_numeric($context['vwap'])) {
                    $values['vwap'] = (string)$context['vwap'];
                }
                // MACD (aplatir vers macd, macd_signal, macd_histogram)
                if (isset($context['macd'])) {
                    $macd = $context['macd'];
                    if (is_array($macd)) {
                        if (isset($macd['macd']) && is_numeric($macd['macd'])) {
                            $values['macd'] = (string)$macd['macd'];
                        }
                        if (isset($macd['signal']) && is_numeric($macd['signal'])) {
                            $values['macd_signal'] = (string)$macd['signal'];
                        }
                        if (isset($macd['hist']) && is_numeric($macd['hist'])) {
                            $values['macd_histogram'] = (string)$macd['hist'];
                        }
                    } elseif (is_numeric($macd)) {
                        $values['macd'] = (string)$macd;
                    }
                }
                // EMA (map 20,50,200)
                if (isset($context['ema']) && is_array($context['ema'])) {
                    foreach ([20, 50, 200] as $period) {
                        if (isset($context['ema'][(string)$period]) && is_numeric($context['ema'][(string)$period])) {
                            $values['ema' . $period] = (string)$context['ema'][(string)$period];
                        } elseif (isset($context['ema'][$period]) && is_numeric($context['ema'][$period])) {
                            $values['ema' . $period] = (string)$context['ema'][$period];
                        }
                    }
                }
                // Bollinger
                if (isset($context['bollinger']) && is_array($context['bollinger'])) {
                    $boll = $context['bollinger'];
                    if (isset($boll['upper']) && is_numeric($boll['upper'])) { $values['bb_upper'] = (string)$boll['upper']; }
                    if (isset($boll['middle']) && is_numeric($boll['middle'])) { $values['bb_middle'] = (string)$boll['middle']; }
                    if (isset($boll['lower']) && is_numeric($boll['lower'])) { $values['bb_lower'] = (string)$boll['lower']; }
                }
                // ADX (valeur unique ou période 14)
                if (isset($context['adx'])) {
                    $adx = $context['adx'];
                    if (is_array($adx)) {
                        $val = $adx['14'] ?? null;
                        if (is_numeric($val)) { $values['adx'] = (string)$val; }
                    } elseif (is_numeric($adx)) {
                        $values['adx'] = (string)$adx;
                    }
                }
                // MA (si le contexte expose ma9/ma21 directement)
                foreach (['ma9', 'ma21'] as $maKey) {
                    if (isset($context[$maKey]) && is_numeric($context[$maKey])) {
                        $values[$maKey] = (string)$context[$maKey];
                    }
                }
                // Close
                if (isset($context['close']) && is_numeric($context['close'])) {
                    $values['close'] = (string)$context['close'];
                }
            }
            // Priorité aux champs directs utiles (ex: ATR calculé en dehors du contexte)
            if (isset($result['atr']) && is_numeric($result['atr'])) { $values['atr'] = (string)$result['atr']; }

            $snapshot = (new IndicatorSnapshot())
                ->setSymbol(strtoupper($symbol))
                ->setTimeframe(\App\Common\Enum\Timeframe::from($tf))
                ->setKlineTime($klineTime->setTimezone(new \DateTimeZone('UTC')))
                ->setValues($values)
                ->setSource('PHP');

            // Déterminer insert vs update pour le log
            $existing = $repo->findOneBy([
                'symbol' => $snapshot->getSymbol(),
                'timeframe' => $snapshot->getTimeframe(),
                'klineTime' => $snapshot->getKlineTime(),
            ]);

            $repo->upsert($snapshot);

            // Info log for observability
            $this->logger->info('[MTF] Indicator snapshot persisted', [
                'symbol' => strtoupper($symbol),
                'timeframe' => $tf,
                'kline_time' => $klineTime->format('Y-m-d H:i:s'),
                'values_count' => count($values),
                'source' => 'PHP',
                'action' => $existing ? 'update' : 'insert',
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('[MTF] Indicator snapshot persist failed', ['symbol' => $symbol, 'tf' => $tf, 'error' => $e->getMessage()]);
        }
    }

    private function isGraceWindowResult(array $result): bool
    {
        return strtoupper((string)($result['status'] ?? '')) === 'GRACE_WINDOW';
    }

    public function getTimeService(): MtfTimeService
    {
        return $this->timeService;
    }

    /**
     * Exécute le cycle MTF complet pour tous les symboles
     * @return \Generator<int, array{symbol: string, result: array, progress: array}, array>
     */
    public function executeMtfCycle(UuidInterface $runId): \Generator
    {
        $this->logger->info('[MTF] Starting MTF cycle', ['run_id' => $runId->toString()]);

        $results = [];
        $now = $this->timeService->getCurrentAlignedUtc();

        // Vérifier le kill switch global
        if (!$this->mtfSwitchRepository->isGlobalSwitchOn()) {
            $this->logger->warning('[MTF] Global kill switch is OFF, skipping cycle');
            $this->auditStep($runId, 'GLOBAL', 'KILL_SWITCH_OFF', 'Global kill switch is OFF');
            yield [
                'symbol' => 'GLOBAL',
                'result' => ['status' => 'SKIPPED', 'reason' => 'Global kill switch OFF'],
                'progress' => ['current' => 0, 'total' => 0, 'percentage' => 0, 'symbol' => 'GLOBAL', 'status' => 'SKIPPED']
            ];
            return ['status' => 'SKIPPED', 'reason' => 'Global kill switch OFF'];
        }

        // Récupérer tous les symboles actifs depuis la base de données
        $activeSymbols = $this->contractRepository->allActiveSymbolNames();

        if (empty($activeSymbols)) {
            $this->logger->warning('[MTF] No active symbols found');
            $this->auditStep($runId, 'GLOBAL', 'NO_ACTIVE_SYMBOLS', 'No active symbols found');
            yield [
                'symbol' => 'GLOBAL',
                'result' => ['status' => 'SKIPPED', 'reason' => 'No active symbols found'],
                'progress' => ['current' => 0, 'total' => 0, 'percentage' => 0, 'symbol' => 'GLOBAL', 'status' => 'SKIPPED']
            ];
            return ['status' => 'SKIPPED', 'reason' => 'No active symbols found'];
        }

        $this->logger->info('[MTF] Processing symbols', [
            'count' => count($activeSymbols),
            'symbols' => array_slice($activeSymbols, 0, 10) // Log only first 10 for brevity
        ]);

        $totalSymbols = count($activeSymbols);
        foreach ($activeSymbols as $index => $symbol) {
            try {
                // Laisser la logique interne gérer start_from_timeframe (pipeline complet)
                $result = $this->processSymbol($symbol, $runId, $now, null);
                $results[$symbol] = $result;

                // Yield progress information
                $progress = [
                    'current' => $index + 1,
                    'total' => $totalSymbols,
                    'percentage' => round((($index + 1) / $totalSymbols) * 100, 2),
                    'symbol' => $symbol,
                    'status' => $result['status'] ?? 'unknown',
                ];

                yield [
                    'symbol' => $symbol,
                    'result' => $result,
                    'progress' => $progress,
                ];
            } catch (\Exception $e) {
                $this->logger->error('[MTF] Error processing symbol', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->auditStep($runId, $symbol, 'ERROR', $e->getMessage());
                $errorResult = ['status' => 'ERROR', 'error' => $e->getMessage()];
                $results[$symbol] = $errorResult;

                // Yield error information
                $progress = [
                    'current' => $index + 1,
                    'total' => $totalSymbols,
                    'percentage' => round((($index + 1) / $totalSymbols) * 100, 2),
                    'symbol' => $symbol,
                    'status' => 'ERROR',
                ];

                yield [
                    'symbol' => $symbol,
                    'result' => $errorResult,
                    'progress' => $progress,
                ];
            }
        }

        $this->logger->info('[MTF] MTF cycle completed', [
            'run_id' => $runId->toString(),
            'results' => $results
        ]);

        // Yield final summary
        yield [
            'symbol' => 'FINAL',
            'result' => [
                'status' => 'COMPLETED',
                'total_symbols' => $totalSymbols,
                'processed_symbols' => count($results),
                'successful_symbols' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'READY')),
                'error_symbols' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'ERROR')),
                'skipped_symbols' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'SKIPPED')),
            ],
            'progress' => [
                'current' => $totalSymbols,
                'total' => $totalSymbols,
                'percentage' => 100.0,
                'symbol' => 'FINAL',
                'status' => 'COMPLETED',
            ],
        ];

        return $results;
    }

    /**
     * Traite un symbole spécifique selon la logique MTF
     */
private function processSymbol(string $symbol, UuidInterface $runId, \DateTimeImmutable $now, ?string $currentTf = null, bool $forceTimeframeCheck = false, bool $forceRun = false, bool $skipContextValidation = false): array
    {
        $this->logger->debug('[MTF] Processing symbol', ['symbol' => $symbol]);

        // Vérifier le kill switch du symbole (sauf si force-run est activé)
        if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbol($symbol)) {
            $this->logger->debug('[MTF] Symbol kill switch is OFF', ['symbol' => $symbol, 'force_run' => $forceRun]);
            $this->auditStep($runId, $symbol, 'KILL_SWITCH_OFF', 'Symbol kill switch is OFF');
            return ['status' => 'SKIPPED', 'reason' => 'Symbol kill switch OFF', 'failed_timeframe' => 'symbol'];
        }

        $state = $this->mtfStateRepository->getOrCreateForSymbol($symbol);
        $validationStates = [];

        // Si un timeframe spécifique est demandé, traiter seulement celui-ci
        if ($currentTf !== null) {
            $timeframeService = match($currentTf) {
                '4h' => $this->timeframe4hService,
                '1h' => $this->timeframe1hService,
                '15m' => $this->timeframe15mService,
                '5m' => $this->timeframe5mService,
                '1m' => $this->timeframe1mService,
                default => throw new \InvalidArgumentException("Invalid timeframe: $currentTf")
            };

            $result = $this->runTimeframeProcessor(
                $timeframeService,
                $symbol,
                $runId,
                $now,
                $validationStates,
                $forceTimeframeCheck,
                $forceRun,
                $skipContextValidation
            );
            // Toujours persister un snapshot pour trace, quel que soit le statut
            try {
                $this->persistIndicatorSnapshot($symbol, $currentTf, $result);
            } catch (\Throwable) {
                // best-effort
            }
            if ($this->isGraceWindowResult($result)) {
                return $result + ['failed_timeframe' => $currentTf];
            }

            if (($result['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, strtoupper($currentTf) . '_VALIDATION_FAILED', $result['reason'] ?? "$currentTf validation failed", [
                    'from_cache' => (bool)($result['from_cache'] ?? false),
                ]);
                return $result + ['failed_timeframe' => $currentTf];
            }

            // Mettre à jour l'état pour le timeframe spécifique
            $timeframeService->updateState($symbol, $result);

            $this->mtfStateRepository->getEntityManager()->flush();

            return [
                'status' => 'READY',
                'signal_side' => $result['signal_side'],
                'context' => ['single_timeframe' => $currentTf],
                'kline_time' => $result['kline_time'],
                'current_price' => $result['current_price'] ?? null,
                'atr' => $result['atr'] ?? null,
                'indicator_context' => $result['indicator_context'] ?? null,
                'execution_tf' => $currentTf,
            ];
        }

        // Logique MTF selon start_from_timeframe (depuis mtf_validations.yaml)
        $cfg = $this->mtfValidationConfig->getConfig();
        $startFrom = strtolower((string)($cfg['validation']['start_from_timeframe'] ?? '4h'));
        // Inclure uniquement les TF à partir de start_from_timeframe vers le bas (aucun TF supérieur)
        $include4h  = in_array($startFrom, ['4h'], true);
        $include1h  = in_array($startFrom, ['4h','1h'], true);
        $include15m = in_array($startFrom, ['4h','1h','15m'], true);
        $include5m  = in_array($startFrom, ['4h','1h','15m','5m'], true);
        $include1m  = in_array($startFrom, ['4h','1h','15m','5m','1m'], true);

        $cacheWarmup = false;
        $cacheWarmupTfs = [];

        $result4h = null;
        if ($include4h) {
            $this->logger->debug('[MTF] Start TF 4h', ['symbol' => $symbol]);
            $hadCache4h = null;
            $cached = $this->getCachedTfResult($symbol, '4h', $hadCache4h);
            if ($hadCache4h === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '4h';
            }
            if ($this->shouldReuseCachedResult($cached, '4h', $symbol)) {
                $this->logger->debug('[MTF] Cache HIT 4h', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result4h = $cached;
            } else {
                $result4h = $this->runTimeframeProcessor(
                $this->timeframe4hService,
                $symbol,
                $runId,
                $now,
                $validationStates,
                $forceTimeframeCheck,
                $forceRun,
                $skipContextValidation
            );
                $this->putCachedTfResult($symbol, '4h', $result4h);
            }
            // Persister systématiquement un snapshot 4h (même en INVALID)
            $this->persistIndicatorSnapshot($symbol, '4h', $result4h);
            if ($this->isGraceWindowResult($result4h)) {
                return $result4h + ['failed_timeframe' => '4h'];
            }

            if (($result4h['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '4H_VALIDATION_FAILED', $result4h['reason'] ?? '4H validation failed', [
                    'timeframe' => '4h',
                    'kline_time' => $result4h['kline_time'] ?? null,
                    'failed_conditions_long' => $result4h['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result4h['failed_conditions_short'] ?? [],
                    'conditions_long' => $result4h['conditions_long'] ?? [],
                    'conditions_short' => $result4h['conditions_short'] ?? [],
                    'current_price' => $result4h['current_price'] ?? null,
                    'atr' => $result4h['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                    'from_cache' => (bool)($result4h['from_cache'] ?? false),
                ]);
                return $result4h + ['failed_timeframe' => '4h'];
            }
            $this->timeframe4hService->updateState($symbol, $result4h);
        }

        $result1h = null;
        if ($include1h) {
            $hadCache1h = null;
            $cached = $this->getCachedTfResult($symbol, '1h', $hadCache1h);
            if ($hadCache1h === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '1h';
            }
            if ($this->shouldReuseCachedResult($cached, '1h', $symbol)) {
                $this->logger->debug('[MTF] Cache HIT 1h', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result1h = $cached;
            } else {
                $result1h = $this->runTimeframeProcessor(
                $this->timeframe1hService,
                $symbol,
                $runId,
                $now,
                $validationStates,
                $forceTimeframeCheck,
                $forceRun,
                $skipContextValidation
            );
                $this->putCachedTfResult($symbol, '1h', $result1h);
            }
            // Persister systématiquement un snapshot 1h
            $this->persistIndicatorSnapshot($symbol, '1h', $result1h);
            if ($this->isGraceWindowResult($result1h)) {
                return $result1h + ['failed_timeframe' => '1h'];
            }

            if (($result1h['status'] ?? null) !== 'VALID') {
                $this->logger->info('[MTF] 1h not VALID, stop cascade', ['symbol' => $symbol, 'reason' => $result1h['reason'] ?? null]);
                $this->auditStep($runId, $symbol, '1H_VALIDATION_FAILED', $result1h['reason'] ?? '1H validation failed', [
                    'timeframe' => '1h',
                    'kline_time' => $result1h['kline_time'] ?? null,
                    'failed_conditions_long' => $result1h['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result1h['failed_conditions_short'] ?? [],
                    'conditions_long' => $result1h['conditions_long'] ?? [],
                    'conditions_short' => $result1h['conditions_short'] ?? [],
                    'current_price' => $result1h['current_price'] ?? null,
                    'atr' => $result1h['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                    'from_cache' => (bool)($result1h['from_cache'] ?? false),
                ]);
                return $result1h + ['failed_timeframe' => '1h'];
            }
            if ($include4h) {
                // Règle: 1h doit matcher 4h si 4h inclus
                $this->logger->debug('[MTF] Check alignment 1h vs 4h', ['symbol' => $symbol, 'h4' => $result4h['signal_side'] ?? 'NONE', 'h1' => $result1h['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe1hService->checkAlignment($result1h, $result4h, '4H');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->logger->info('[MTF] Alignment failed 1h vs 4h, stop cascade', ['symbol' => $symbol]);
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '1h side != 4h side', [
                        '4h' => $result4h['signal_side'] ?? 'NONE',
                        '1h' => $result1h['signal_side'] ?? 'NONE',
                        'timeframe' => '1h',
                        'passed' => false,
                        'severity' => 1,
                        'from_cache' => (bool)($result1h['from_cache'] ?? false) && (bool)($result4h['from_cache'] ?? false),
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe1hService->updateState($symbol, $result1h);
        }

        // Étape 15m (seulement si incluse)
        $result15m = null;
        if ($include15m) {
            $this->logger->debug('[MTF] Start TF 15m', ['symbol' => $symbol]);
            $hadCache15m = null;
            $cached = $this->getCachedTfResult($symbol, '15m', $hadCache15m);
            if ($hadCache15m === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '15m';
            }
            if ($this->shouldReuseCachedResult($cached, '15m', $symbol)) {
                $this->logger->debug('[MTF] Cache HIT 15m', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result15m = $cached;
            } else {
                $result15m = $this->runTimeframeProcessor(
                $this->timeframe15mService,
                $symbol,
                $runId,
                $now,
                $validationStates,
                $forceTimeframeCheck,
                $forceRun,
                $skipContextValidation
            );
                $this->putCachedTfResult($symbol, '15m', $result15m);
            }
            // Persister systématiquement un snapshot 15m
            $this->persistIndicatorSnapshot($symbol, '15m', $result15m);
            if ($this->isGraceWindowResult($result15m)) {
                return $result15m + ['failed_timeframe' => '15m'];
            }

            if (($result15m['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '15M_VALIDATION_FAILED', $result15m['reason'] ?? '15M validation failed', [
                    'timeframe' => '15m',
                    'kline_time' => $result15m['kline_time'] ?? null,
                    'failed_conditions_long' => $result15m['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result15m['failed_conditions_short'] ?? [],
                    'conditions_long' => $result15m['conditions_long'] ?? [],
                    'conditions_short' => $result15m['conditions_short'] ?? [],
                    'current_price' => $result15m['current_price'] ?? null,
                    'atr' => $result15m['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                    'from_cache' => (bool)($result15m['from_cache'] ?? false),
                ]);
                return $result15m + ['failed_timeframe' => '15m'];
            }
            // Règle: 15m doit matcher 1h si 1h est inclus
            if ($include1h && is_array($result1h)) {
                $this->logger->debug('[MTF] Check alignment 15m vs 1h', ['symbol' => $symbol, 'm15' => $result15m['signal_side'] ?? 'NONE', 'h1' => $result1h['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe15mService->checkAlignment($result15m, $result1h, '1H');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->logger->info('[MTF] Alignment failed 15m vs 1h, stop cascade', ['symbol' => $symbol]);
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '15m side != 1h side', [
                        '1h' => $result1h['signal_side'] ?? 'NONE',
                        '15m' => $result15m['signal_side'] ?? 'NONE',
                        'timeframe' => '15m',
                        'passed' => false,
                        'severity' => 1,
                        'from_cache' => (bool)($result15m['from_cache'] ?? false) && (bool)($result1h['from_cache'] ?? false),
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe15mService->updateState($symbol, $result15m);
        }

        // Étape 5m (seulement si incluse)
        $result5m = null;
        if ($include5m) {
            $this->logger->debug('[MTF] Start TF 5m', ['symbol' => $symbol]);
            $hadCache5m = null;
            $cached = $this->getCachedTfResult($symbol, '5m', $hadCache5m);
            if ($hadCache5m === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '5m';
            }
            if ($this->shouldReuseCachedResult($cached, '5m', $symbol)) {
                $this->logger->debug('[MTF] Cache HIT 5m', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result5m = $cached;
            } else {
                $result5m = $this->runTimeframeProcessor(
                $this->timeframe5mService,
                $symbol,
                $runId,
                $now,
                $validationStates,
                $forceTimeframeCheck,
                $forceRun,
                $skipContextValidation
            );
                $this->putCachedTfResult($symbol, '5m', $result5m);
            }
            // Calculer ATR 5m (toujours)
            try {
                $result5m['atr'] = $this->computeAtrValue($symbol, '5m');
            } catch (\Throwable $e) {
                $this->logger->error('[MTF] ATR computation exception', [
                    'symbol' => $symbol,
                    'timeframe' => '5m',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $result5m['atr'] = null;  // Explicitement null au lieu de laisser indéfini
            }

            // Persister systématiquement un snapshot 5m (après calcul ATR)
            $this->persistIndicatorSnapshot($symbol, '5m', $result5m);
            if ($this->isGraceWindowResult($result5m)) {
                return $result5m + ['failed_timeframe' => '5m'];
            }

            if (($result5m['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '5M_VALIDATION_FAILED', $result5m['reason'] ?? '5M validation failed', [
                    'timeframe' => '5m',
                    'kline_time' => $result5m['kline_time'] ?? null,
                    'failed_conditions_long' => $result5m['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result5m['failed_conditions_short'] ?? [],
                    'conditions_long' => $result5m['conditions_long'] ?? [],
                    'conditions_short' => $result5m['conditions_short'] ?? [],
                    'current_price' => $result5m['current_price'] ?? null,
                    'atr' => $result5m['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                    'from_cache' => (bool)($result5m['from_cache'] ?? false),
                ]);
                return $result5m + ['failed_timeframe' => '5m'];
            }
            // Règle: 5m doit matcher 15m si 15m est inclus
            if ($include15m && is_array($result15m)) {
                $this->logger->debug('[MTF] Check alignment 5m vs 15m', ['symbol' => $symbol, 'm5' => $result5m['signal_side'] ?? 'NONE', 'm15' => $result15m['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe5mService->checkAlignment($result5m, $result15m, '15M');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->logger->info('[MTF] Alignment failed 5m vs 15m, stop cascade', ['symbol' => $symbol]);
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '5m side != 15m side', [
                        '15m' => $result15m['signal_side'] ?? 'NONE',
                        '5m' => $result5m['signal_side'] ?? 'NONE',
                        'timeframe' => '5m',
                        'passed' => false,
                        'severity' => 1,
                        'from_cache' => (bool)($result5m['from_cache'] ?? false) && (bool)($result15m['from_cache'] ?? false),
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe5mService->updateState($symbol, $result5m);
        }

        // Étape 1m (seulement si incluse)
        $result1m = null;
        if ($include1m) {
            $this->logger->debug('[MTF] Start TF 1m', ['symbol' => $symbol]);
            $hadCache1m = null;
            $cached = $this->getCachedTfResult($symbol, '1m', $hadCache1m);
            if ($hadCache1m === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '1m';
            }
            if ($this->shouldReuseCachedResult($cached, '1m', $symbol)) {
                $this->logger->debug('[MTF] Cache HIT 1m', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result1m = $cached;
            } else {
                $result1m = $this->runTimeframeProcessor(
                $this->timeframe1mService,
                $symbol,
                $runId,
                $now,
                $validationStates,
                $forceTimeframeCheck,
                $forceRun,
                $skipContextValidation
            );
                $this->putCachedTfResult($symbol, '1m', $result1m);
            }
            // Calculer ATR 1m (toujours)
            try {
                $result1m['atr'] = $this->computeAtrValue($symbol, '1m');
            } catch (\Throwable $e) {
                $this->logger->error('[MTF] ATR computation exception', [
                    'symbol' => $symbol,
                    'timeframe' => '1m',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $result1m['atr'] = null;  // Explicitement null au lieu de laisser indéfini
            }

            // Persister systématiquement un snapshot 1m (après calcul ATR)
            $this->persistIndicatorSnapshot($symbol, '1m', $result1m);
            if ($this->isGraceWindowResult($result1m)) {
                return $result1m + ['failed_timeframe' => '1m'];
            }

            if (($result1m['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '1M_VALIDATION_FAILED', $result1m['reason'] ?? '1M validation failed', [
                    'timeframe' => '1m',
                    'kline_time' => $result1m['kline_time'] ?? null,
                    'failed_conditions_long' => $result1m['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result1m['failed_conditions_short'] ?? [],
                    'conditions_long' => $result1m['conditions_long'] ?? [],
                    'conditions_short' => $result1m['conditions_short'] ?? [],
                    'current_price' => $result1m['current_price'] ?? null,
                    'atr' => $result1m['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                    'from_cache' => (bool)($result1m['from_cache'] ?? false),
                ]);
                return $result1m + ['failed_timeframe' => '1m'];
            }

            // Log dédié après validation 1m (positions_flow)
            $this->positionsFlowLogger->info('[PositionsFlow] 1m VALIDATED', [
                'symbol' => $symbol,
                'signal_side' => $result1m['signal_side'] ?? 'NONE',
                'kline_time' => isset($result1m['kline_time']) && $result1m['kline_time'] instanceof \DateTimeImmutable ? $result1m['kline_time']->format('Y-m-d H:i:s') : null,
                'current_price' => $result1m['current_price'] ?? null,
                'atr' => $result1m['atr'] ?? null,
            ]);
            // Règle: 1m doit matcher 5m si 5m est inclus
            if ($include5m && is_array($result5m)) {
                $this->logger->debug('[MTF] Check alignment 1m vs 5m', ['symbol' => $symbol, 'm1' => $result1m['signal_side'] ?? 'NONE', 'm5' => $result5m['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe1mService->checkAlignment($result1m, $result5m, '5M');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->logger->info('[MTF] Alignment failed 1m vs 5m, stop cascade', ['symbol' => $symbol]);
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '1m side != 5m side', [
                        '5m' => $result5m['signal_side'] ?? 'NONE',
                        '1m' => $result1m['signal_side'] ?? 'NONE',
                        'timeframe' => '1m',
                        'passed' => false,
                        'severity' => 1,
                        'from_cache' => (bool)($result1m['from_cache'] ?? false) && (bool)($result5m['from_cache'] ?? false),
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe1mService->updateState($symbol, $result1m);
        }

        // Sauvegarder l'état
        $this->mtfStateRepository->getEntityManager()->flush();

        // Vérification stricte de la chaîne complète: tous les TF inclus doivent être VALID
        // et leurs sides doivent être identiques (LONG ou SHORT), sinon statut INVALID
        $chainTfs = [];
        if ($include4h)  { $chainTfs['4h']  = is_array($result4h)  ? $result4h  : []; }
        if ($include1h)  { $chainTfs['1h']  = is_array($result1h)  ? $result1h  : []; }
        if ($include15m) { $chainTfs['15m'] = is_array($result15m) ? $result15m : []; }
        if ($include5m)  { $chainTfs['5m']  = is_array($result5m)  ? $result5m  : []; }
        if ($include1m)  { $chainTfs['1m']  = is_array($result1m)  ? $result1m  : []; }

        $firstSide = null;
        foreach ($chainTfs as $tfKey => $tfResult) {
            $status = strtoupper((string)($tfResult['status'] ?? ''));
            if ($status !== 'VALID') {
                $this->logger->info('[MTF] Chain invalid: timeframe not VALID', ['symbol' => $symbol, 'tf' => $tfKey, 'status' => $status]);
                return [
                    'status' => 'INVALID',
                    'failed_timeframe' => $tfKey,
                    'reason' => 'CHAIN_NOT_VALIDATED',
                ];
            }
            $side = strtoupper((string)($tfResult['signal_side'] ?? 'NONE'));
            if (!in_array($side, ['LONG','SHORT'], true)) {
                $this->logger->info('[MTF] Chain invalid: side NONE', ['symbol' => $symbol, 'tf' => $tfKey]);
                return [
                    'status' => 'INVALID',
                    'failed_timeframe' => $tfKey,
                    'reason' => 'CHAIN_SIDE_NONE',
                ];
            }
            if ($firstSide === null) {
                $firstSide = $side;
            } elseif ($side !== $firstSide) {
                $this->logger->info('[MTF] Chain invalid: side mismatch', ['symbol' => $symbol, 'tf' => $tfKey, 'expected' => $firstSide, 'actual' => $side]);
                return [
                    'status' => 'INVALID',
                    'failed_timeframe' => $tfKey,
                    'reason' => 'CHAIN_SIDE_MISMATCH',
                ];
            }
        }

        // Construire knownSignals pour contexte
        $knownSignals = [];
        foreach ($validationStates as $vs) {
            $knownSignals[$vs['tf']] = ['signal' => strtoupper((string)($vs['signal_side'] ?? 'NONE'))];
        }

        // Choix TF exécution simple (15m>5m>1m)
        $mtfByTf = [
            '15m' => is_array($result15m) ? $result15m : [],
            '5m'  => is_array($result5m) ? $result5m : [],
            '1m'  => is_array($result1m) ? $result1m : [],
        ];
        $available = [
            '15m' => is_array($result15m) ? ($result15m['signal_side'] ?? 'NONE') : 'NONE',
            '5m'  => is_array($result5m) ? ($result5m['signal_side'] ?? 'NONE') : 'NONE',
            '1m'  => is_array($result1m) ? ($result1m['signal_side'] ?? 'NONE') : 'NONE',
        ];
        // Option B: privilégier 1m (si validé et aligné), sinon 5m, sinon 15m
        // Les vérifications d'alignement 1m↔5m et 5m↔15m ont déjà été effectuées plus haut.
        $prefOrder = ['1m','5m','15m'];
        $currentTf = '1m';
        foreach ($prefOrder as $tf) {
            $side = strtoupper((string)($available[$tf] ?? 'NONE'));
            if ($side !== 'NONE') { $currentTf = $tf; break; }
        }
        $currentSignal = strtoupper((string)($available[$currentTf] ?? 'NONE'));
        $selectedTfSnapshot = $mtfByTf[$currentTf] ?? [];
        $selectedPrice = $selectedTfSnapshot['current_price'] ?? null;
        $selectedAtr = $selectedTfSnapshot['atr'] ?? null;
        $selectedContext = $selectedTfSnapshot['indicator_context'] ?? null;
        $selectedKlineTime = $selectedTfSnapshot['kline_time'] ?? (is_array($result15m) ? ($result15m['kline_time'] ?? null) : null);

        $contextSummary = $this->signalValidationService->buildContextSummary($knownSignals, $currentTf, $currentSignal);
        $this->logger->info('[MTF] Context summary', [ 'symbol' => $symbol, 'current_tf' => $currentTf ] + $contextSummary);

        $chainFromCache = $chainTfs !== [] && array_reduce(
            $chainTfs,
            static fn(bool $carry, array $tfResult): bool => $carry && (($tfResult['from_cache'] ?? false) === true),
            true
        );

        $shouldGrace = $cacheWarmup && !$forceRun;
        if (!$shouldGrace) {
            $auditData = [
                'current_tf' => $currentTf,
                'timeframe' => $currentTf,
                'passed' => $currentSignal !== 'NONE',
                'severity' => 0,
                'from_cache' => $chainFromCache,
            ] + $contextSummary;

            $this->auditStep($runId, $symbol, 'MTF_CONTEXT', null, $auditData);
        }

        // À ce stade, la chaîne complète est VALID et les sides sont identiques
        $consistentSide = $firstSide ?? 'NONE';

        if ($shouldGrace) {
            $warmupTfs = array_values(array_unique($cacheWarmupTfs));
            $this->logger->info('[MTF] Cache warm-up detected, skipping trading decision', [
                'symbol' => $symbol,
                'timeframes' => $warmupTfs,
            ]);
            $contextSummaryWarm = $contextSummary;
            $contextSummaryWarm['cache_warmup'] = $warmupTfs;

            return [
                'status' => 'GRACE_WINDOW',
                'signal_side' => 'NONE',
                'context' => $contextSummaryWarm,
                'kline_time' => $selectedKlineTime,
                'current_price' => $selectedPrice,
                'atr' => $selectedAtr,
                'indicator_context' => $selectedContext,
                'execution_tf' => $currentTf,
                'failed_timeframe' => 'cache_warmup',
                'reason' => 'CACHE_WARMUP',
            ];
        }

        // Pour rester focalisé sur la demande, on n’applique pas de filtres supplémentaires ici.
        return [
            'status' => 'READY',
            'signal_side' => $consistentSide,
            'context' => $contextSummary,
            'kline_time' => $selectedKlineTime,
            'current_price' => $selectedPrice,
            'atr' => $selectedAtr,
            'indicator_context' => $selectedContext,
            'execution_tf' => $currentTf,
        ];
    }

    private function computeAtrValue(string $symbol, string $tf): ?float
    {
        // Paramètres par défaut (trading.yml): period=14, method=wilder
        $period = 14;
        $method = 'wilder';
        $tfEnum = Timeframe::from($tf);

        // Attempt 1: Retrieve the klines
        $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 200);

        $this->logger->debug('[MTF] ATR computation start', [
            'symbol' => $symbol,
            'tf' => $tf,
            'klines_count' => count($klines),
            'period' => $period,
        ]);

        if (empty($klines)) {
            $this->logger->warning('[MTF] No klines for ATR computation', [
                'symbol' => $symbol,
                'tf' => $tf,
            ]);
            return null;
        }

        $ohlc = [];
        foreach ($klines as $k) {
            $ohlc[] = [
                'high' => (float)$k->high->toFloat(),
                'low' => (float)$k->low->toFloat(),
                'close' => (float)$k->close->toFloat(),
            ];
        }

        $calc = new \App\Indicator\Core\AtrCalculator($this->logger);
        $atr = $calc->computeWithRules($ohlc, $period, $method, strtolower($tf));

        // GARDE : Si ATR = 0, réessayer une fois (les klines étaient peut-être en cours d'insertion)
        if ($atr === 0.0) {
            $this->logger->warning('[TO_BE_DELETED][MTF_ATR_ZERO]', [
                'symbol' => $symbol,
                'tf' => $tf,
                'ohlc_count' => count($ohlc),
            ]);
            $this->logger->warning('[MTF] ATR = 0.0, retrying klines fetch', [
                'symbol' => $symbol,
                'tf' => $tf,
                'first_attempt_klines' => count($klines),
                'first_candle' => $ohlc[0] ?? null,
                'last_candle' => $ohlc[count($ohlc) - 1] ?? null,
            ]);

            // Attendre 100ms pour laisser les klines s'insérer en DB
            usleep(100000);

            // Tentative 2 : Récupérer les klines à nouveau
            $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 200);

            if (empty($klines)) {
                $this->logger->error('[MTF] No klines on retry for ATR computation', [
                    'symbol' => $symbol,
                    'tf' => $tf,
                ]);
                return null;
            }

            $ohlc = [];
            foreach ($klines as $k) {
                $ohlc[] = [
                    'high' => (float)$k->high->toFloat(),
                    'low' => (float)$k->low->toFloat(),
                    'close' => (float)$k->close->toFloat(),
                ];
            }

            $atr = $calc->computeWithRules($ohlc, $period, $method, strtolower($tf));

            if ($atr === 0.0) {
                $this->logger->error('[TO_BE_DELETED][MTF_ATR_ZERO_RETRY]', [
                    'symbol' => $symbol,
                    'tf' => $tf,
                    'retry_klines_count' => count($klines),
                ]);
                $this->logger->error('[MTF] ATR still 0.0 after retry', [
                    'symbol' => $symbol,
                    'tf' => $tf,
                    'retry_klines_count' => count($klines),
                    'ohlc_count' => count($ohlc),
                    'sample_candles' => [
                        'first' => $ohlc[0] ?? null,
                        'mid' => $ohlc[(int)(count($ohlc) / 2)] ?? null,
                        'last' => $ohlc[count($ohlc) - 1] ?? null,
                    ],
                ]);
                // Retourner null au lieu de 0.0 pour indiquer un ATR invalide
                return null;
            }

            $this->logger->info('[MTF] ATR computed successfully on retry', [
                'symbol' => $symbol,
                'tf' => $tf,
                'atr' => $atr,
            ]);
        }

        $this->logger->debug('[MTF] ATR computation result', [
            'symbol' => $symbol,
            'tf' => $tf,
            'atr' => $atr,
            'is_valid' => $atr !== null && $atr > 0.0,
        ]);

        return $atr;
    }

    /**
     * Appelle un processeur de timeframe en respectant le nouveau contrat
     *
     * @param array<int, array<string, mixed>> $collector
     * @return array<string, mixed>
     */
    private function runTimeframeProcessor(
        TimeframeProcessorInterface $processor,
        string $symbol,
        UuidInterface $runId,
        \DateTimeImmutable $now,
        array &$collector,
        bool $forceTimeframeCheck,
        bool $forceRun,
        bool $skipContextValidation = false
    ): array {
        $context = ValidationContextDto::create(
            runId: $runId->toString(),
            now: $now,
            collector: $collector,
            forceTimeframeCheck: $forceTimeframeCheck,
            forceRun: $forceRun,
            skipContextValidation: $skipContextValidation
        );

        $resultDto = $processor->processTimeframe($symbol, $context);
        $result = $resultDto->toArray();

        $collector[] = [
            'tf' => $resultDto->timeframe,
            'status' => $resultDto->status,
            'signal_side' => $resultDto->signalSide ?? 'NONE',
            'kline_time' => $resultDto->klineTime,
        ];

        return $result;
    }

    /**
     * NOUVELLE MÉTHODE : Remplit les klines manquantes en masse
     */
    private function fillMissingKlinesInBulk(
        string $symbol,
        Timeframe $timeframe,
        int $requiredLimit,
        \DateTimeImmutable $now,
        UuidInterface $runId
    ): void {
        if (!$this->klineJsonIngestion) {
            $this->logger->warning('[MTF] KlineJsonIngestionService not available, skipping bulk fill');
            return;
        }

        $this->logger->info('[MTF] Filling missing klines in bulk', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'required_limit' => $requiredLimit
        ]);

        // Calculer la période à récupérer
        $intervalMinutes = $timeframe->getStepInMinutes();
        $endDatetime = (clone $now)->sub(new \DateInterval('PT' . ($requiredLimit * $intervalMinutes) . 'M'));

        // Fetch toutes les klines manquantes d'un coup
        $fetchedKlines = $this->klineProvider->getKlinesInWindow(
            $symbol,
            $timeframe,
            $endDatetime,
            $now,
            $requiredLimit * 2 // Récupérer un peu plus pour être sûr
        );

        if (empty($fetchedKlines)) {
            $this->logger->warning('[MTF] No klines fetched from BitMart', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value
            ]);
            return;
        }

        // Insertion en masse via la fonction SQL JSON
        $result = $this->klineJsonIngestion->ingestKlinesBatch($fetchedKlines, $symbol, $timeframe->value);

        $this->logger->info('[MTF] Bulk klines insertion completed', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'fetched_count' => count($fetchedKlines),
            'inserted_count' => $result->count,
            'duration_ms' => $result->durationMs
        ]);
    }

    /**
     * Ancienne méthode processTimeframe supprimée - logique déplacée vers les services de timeframe spécialisés
     */

    private function resolveInvalidReason(
        array $evaluation,
        array $conditionsLong,
        array $conditionsShort,
        array $failedLong,
        array $failedShort
    ): string {
        $provided = $evaluation['reason'] ?? null;
        if (is_string($provided)) {
            $normalized = strtoupper(trim($provided));
            if ($normalized !== '' && $normalized !== 'NO_SIGNAL') {
                return $provided;
            }
        }

        return $this->buildFailedConditionsReason($conditionsLong, $conditionsShort, $failedLong, $failedShort);
    }

    private function buildFailedConditionsReason(
        array $conditionsLong,
        array $conditionsShort,
        array $failedLong,
        array $failedShort
    ): string {
        $parts = [];

        if ($conditionsLong === []) {
            $parts[] = 'LONG_NOT_CONFIGURED';
        } elseif ($failedLong !== []) {
            $parts[] = 'LONG_FAILED(' . implode(',', $failedLong) . ')';
        }

        if ($conditionsShort === []) {
            $parts[] = 'SHORT_NOT_CONFIGURED';
        } elseif ($failedShort !== []) {
            $parts[] = 'SHORT_FAILED(' . implode(',', $failedShort) . ')';
        }

        $parts = array_values(array_filter($parts, fn($part) => $part !== ''));
        if ($parts === []) {
            return 'CONDITIONS_NOT_MET';
        }

        return implode(' | ', $parts);
    }

    private function getConsistentSideSimple(array $states): string
    {
        $sides = array_map(fn($s) => strtoupper((string)($s['side'] ?? $s['signal_side'] ?? 'NONE')), $states);
        $sides = array_filter($sides, fn($v) => $v !== 'NONE');
        if ($sides === []) return 'NONE';
        $uniq = array_unique($sides);
        return count($uniq) === 1 ? reset($uniq) : 'NONE';
    }

    /**
     * Enregistre une étape d'audit (signature et implémentation d'origine)
     */
    private function auditStep(\Ramsey\Uuid\UuidInterface $runId, string $symbol, string $step, ?string $message = null, array $data = []): void
    {
        $audit = new MtfAudit();
        $audit->setRunId($runId);
        $audit->setSymbol($symbol);
        $audit->setStep($step);
        $audit->setCause($message);
        // Enrichir les détails avec structure standard si présente dans $data
        $details = $data;
        if (!array_key_exists('passed', $details)) {
            $details['passed'] = (bool)($data['passed'] ?? false);
        }
        if (!array_key_exists('conditions_passed', $details) && isset($data['conditions_long'], $data['conditions_short'])) {
            $details['conditions_passed'] = array_keys(array_filter(array_merge($data['conditions_long'] ?? [], $data['conditions_short'] ?? []), fn($v) => is_array($v) ? (($v['passed'] ?? false) === true) : (bool)$v));
        }
        if (!array_key_exists('conditions_failed', $details) && isset($data['failed_conditions_long'], $data['failed_conditions_short'])) {
            $details['conditions_failed'] = array_values(array_merge($data['failed_conditions_long'] ?? [], $data['failed_conditions_short'] ?? []));
        }
        if (!array_key_exists('metrics', $details) && isset($data['current_price'], $data['atr'])) {
            $details['metrics'] = [
                'price' => $data['current_price'],
                'atr' => $data['atr'],
                'atr_rel' => (isset($data['current_price'], $data['atr']) && (float)$data['current_price'] > 0) ? ((float)$data['atr'] / (float)$data['current_price']) : null,
            ];
        }
        if (!array_key_exists('guard_values', $details) && isset($data['min_bars'], $data['bars_count'])) {
            $details['guard_values'] = [
                'min_bars' => $data['min_bars'],
                'bars_count' => $data['bars_count'],
            ];
        }
        $audit->setDetails($details);

        // Optionnel: timeframe & candle_open_ts si fournis
        if (isset($data['timeframe']) && is_string($data['timeframe'])) {
            try {
                $audit->setTimeframe(\App\Common\Enum\Timeframe::from($data['timeframe']));
            } catch (\Throwable) {}
        }
        if (isset($data['kline_time']) && $data['kline_time'] instanceof \DateTimeImmutable) {
            $audit->setCandleOpenTs($data['kline_time']);
        }
        if (isset($data['severity']) && is_numeric($data['severity'])) {
            $audit->setSeverity((int)$data['severity']);
        }
        $audit->setCreatedAt($this->clock->now());

        // Ajouter le run_id aux détails
        $details = $audit->getDetails();
        $details['run_id'] = $runId->toString();
        $audit->setDetails($details);

        // Dispatcher l'événement pour déléger la persistance au subscriber
        $this->eventDispatcher->dispatch(new MtfAuditEvent(
            $audit->getSymbol(),
            $audit->getStep(),
            $audit->getCause(),
            $audit->getDetails(),
            $audit->getSeverity()
        ), MtfAuditEvent::NAME);
    }

    /**
     * Persiste les résultats MTF (cache de validation)
     */
    private function persistMtfResults(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $klineTime,
        string $signalSide,
        array $evaluation,
        array $collector
    ): void {
        try {
            // Persister le cache de validation
            if ($this->validationCache !== null) {
                $status = match ($signalSide) {
                    'LONG', 'SHORT' => 'VALID',
                    'NONE' => 'INVALID',
                    default => 'PENDING'
                };

                $details = [
                    'signal_side' => $signalSide,
                    'conditions_long' => $evaluation['conditions_long'] ?? [],
                    'conditions_short' => $evaluation['conditions_short'] ?? [],
                    'indicator_context' => $evaluation['indicator_context'] ?? [],
                    'mtf_collector' => $collector,
                    'persisted_by' => 'mtf_service'
                ];

                // Calculer l'expiration selon le timeframe (moins 1 seconde pour éviter les problèmes de timing)
                $now = $this->timeService->getCurrentAlignedUtc();
                $expirationTime = $this->timeService->getValidationCacheTtl($now, $timeframe);
                $expirationTime = $expirationTime->modify('-1 second');
                $expirationMinutes = (int) ceil(($expirationTime->getTimestamp() - $now->getTimestamp()) / 60);

                $this->validationCache->cacheMtfValidation(
                    $symbol,
                    $timeframe,
                    $klineTime,
                    $status,
                    $details,
                    $expirationMinutes
                );

                $this->logger->info('MTF validation cached', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'status' => $status,
                    'kline_time' => $klineTime->format('Y-m-d H:i:s'),
                    'expiration_minutes' => $expirationMinutes,
                    'expiration_time' => $expirationTime->format('Y-m-d H:i:s')
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to persist MTF results', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Expose le traitement d'un symbole pour délégation externe.
     * @return \Generator<int, array{symbol: string, result: array, progress: array}, array>
     */
    public function runForSymbol(\Ramsey\Uuid\UuidInterface $runId, string $symbol, \DateTimeImmutable $now, ?string $currentTf = null, bool $forceTimeframeCheck = false, bool $forceRun = false, bool $skipContextValidation = false): \Generator
    {
        $result = $this->processSymbol($symbol, $runId, $now, $currentTf, $forceTimeframeCheck, $forceRun, $skipContextValidation);

        // Yield progress information for single symbol
        $progress = [
            'current' => 1,
            'total' => 1,
            'percentage' => 100.0,
            'symbol' => $symbol,
            'status' => $result['status'] ?? 'unknown',
        ];

        yield [
            'symbol' => $symbol,
            'result' => $result,
            'progress' => $progress,
        ];

        return $result;
    }
}
