<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Common\Enum\Timeframe;
use App\Config\MtfConfigProviderInterface;
use App\Config\MtfValidationConfig;
use App\Config\MtfValidationConfigProvider;
use App\Config\TradeEntryConfigProvider;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
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
use App\Indicator\Message\IndicatorSnapshotProjectionMessage;
use App\Contract\Signal\SignalValidationServiceInterface;
use App\MtfValidator\Service\Timeframe\Timeframe4hService;
use App\MtfValidator\Service\Timeframe\Timeframe1hService;
use App\MtfValidator\Service\Timeframe\Timeframe15mService;
use App\MtfValidator\Service\Timeframe\Timeframe5mService;
use App\MtfValidator\Service\Timeframe\Timeframe1mService;
use App\MtfValidator\Service\ContextDecisionService;
use App\MtfValidator\Service\ExecutionTimeframeDecisionService;
use Doctrine\ORM\EntityManagerInterface;
use App\Logging\TraceIdProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class MtfService
{
    public function __construct(
        private readonly MtfTimeService $timeService,
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly ContractRepository $contractRepository,
        private readonly SignalValidationServiceInterface $signalValidationService,
        private readonly LoggerInterface $mtfLogger,
        private readonly MtfValidationConfig $mtfValidationConfig,
        private readonly KlineProviderInterface $klineProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Timeframe4hService $timeframe4hService,
        private readonly Timeframe1hService $timeframe1hService,
        private readonly Timeframe15mService $timeframe15mService,
        private readonly Timeframe5mService $timeframe5mService,
        private readonly Timeframe1mService $timeframe1mService,
        private readonly ContextDecisionService $contextDecisionService,
        private readonly ExecutionTimeframeDecisionService $executionTimeframeDecisionService,
        private readonly MessageBusInterface $messageBus,
        private readonly ?DbValidationCache $validationCache = null,
        private readonly ?KlineJsonIngestionService $klineJsonIngestion = null,
        private readonly ?MtfValidationConfigProvider $mtfValidationConfigProvider = null,
        private readonly ?TradeEntryConfigProvider $tradeEntryConfigProvider = null,
        private readonly ?ConditionRegistry $conditionRegistry = null,
        private readonly ?TraceIdProvider $traceIdProvider = null,
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
    $minute = (int) $now->format('i');
    $hour = (int) $now->format('H');

    return match ($tf) {
        '4h' => $now
            ->setTime($hour - ($hour % 4), 0, 0)
            ->modify('+4 hours')
            ->modify('-1 second'),
        '1h' => $now
            ->setTime($hour, 0, 0)
            ->modify('+1 hour')
            ->modify('-1 second'),
        '15m' => $now
            ->setTime($hour, $minute - ($minute % 15), 0)
            ->modify('+15 minutes')
            ->modify('-1 second'),
        '5m' => $now
            ->setTime($hour, $minute - ($minute % 5), 0)
            ->modify('+5 minutes')
            ->modify('-1 second'),
        '1m' => $now
            ->setTime($hour, $minute, 0)
            ->modify('+1 minute')
            ->modify('-1 second'),
        default => throw new \InvalidArgumentException(sprintf('Invalid timeframe: %s', $tf)),
        };
}


    /**
 * Decide whether a cached timeframe result can be reused as-is.
 */
private function shouldReuseCachedResult(?array $cached, string $timeframe, string $symbol): bool
{
    // Pas de cache → on ne réutilise pas
    if (!is_array($cached)) {
        return false;
    }

    // On ne réutilise jamais un résultat non VALID
    $status = strtoupper((string)($cached['status'] ?? ''));
    if ($status !== 'VALID') {
        return false;
    }

    // Si pas de kline_time exploitable → on reste prudent, on ne réutilise pas
    $klineTime = $cached['kline_time'] ?? null;
    if (!$klineTime instanceof \DateTimeImmutable) {
        return false;
    }

    // Vérification de fraîcheur (en plus du isExpired() côté entity)
    $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

    $interval = match ($timeframe) {
        '4h' => new \DateInterval('PT4H'),
        '1h' => new \DateInterval('PT1H'),
        '15m' => new \DateInterval('PT15M'),
        '5m' => new \DateInterval('PT5M'),
        '1m' => new \DateInterval('PT1M'),
        default => throw new \InvalidArgumentException('Invalid timeframe'),
    };

    $expiresAt = $klineTime->add($interval);

    // Si on est déjà au-delà de la fin de vie de la bougie → on ne réutilise pas
    if ($now >= $expiresAt) {
        return false;
    }

    // Cache VALIDE + bougie encore dans sa fenêtre temporelle → on peut réutiliser
    return true;
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
                'kline_time' => isset($payload['kline_time']) ? $this->parseKlineTime($payload['kline_time']) : null,
                'from_cache' => true,
            ];
        } catch (\Throwable $e) {
            $this->mtfLogger->debug('[MTF] Cache read failed', ['symbol' => $symbol, 'tf' => $tf, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function putCachedTfResult(string $symbol, string $tf, array $result): void
    {
        if ($this->isGraceWindowResult($result)) {
            $this->mtfLogger->debug('[MTF] Skip cache write for grace window result', ['symbol' => $symbol, 'tf' => $tf]);
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
            $this->mtfLogger->debug('[MTF] Cache write failed', ['symbol' => $symbol, 'tf' => $tf, 'error' => $e->getMessage()]);
        }
    }

    private function persistIndicatorSnapshot(string $symbol, string $tf, array $result, ?string $runId = null): void
    {
        if ($this->isGraceWindowResult($result)) {
            $this->mtfLogger->debug('[MTF] Indicator snapshot skipped (grace window)', [
                'symbol' => strtoupper($symbol),
                'timeframe' => $tf,
                'status' => $result['status'] ?? null,
                'run_id' => $runId,
            ]);
            return;
        }
        try {
            $klineTime = $this->parseKlineTime($result['kline_time'] ?? null);
            if (!$klineTime instanceof \DateTimeImmutable) {
                $this->mtfLogger->warning('[MTF] Indicator snapshot skipped (invalid kline_time)', [
                    'symbol' => strtoupper($symbol),
                    'timeframe' => $tf,
                    'status' => $result['status'] ?? null,
                    'raw_kline_time' => $result['kline_time'] ?? null,
                    'run_id' => $runId,
                ]);
                return;
            }

            $values = [];
            $context = $result['indicator_context'] ?? [];
            if (is_array($context)) {
                if (isset($context['rsi']) && is_numeric($context['rsi'])) {
                    $values['rsi'] = (float) $context['rsi'];
                }
                if (isset($context['atr']) && is_numeric($context['atr'])) {
                    $values['atr'] = (string) $context['atr'];
                }
                if (isset($context['vwap']) && is_numeric($context['vwap'])) {
                    $values['vwap'] = (string) $context['vwap'];
                }
                if (isset($context['macd'])) {
                    $macd = $context['macd'];
                    if (is_array($macd)) {
                        if (isset($macd['macd']) && is_numeric($macd['macd'])) {
                            $values['macd'] = (string) $macd['macd'];
                        }
                        if (isset($macd['signal']) && is_numeric($macd['signal'])) {
                            $values['macd_signal'] = (string) $macd['signal'];
                        }
                        if (isset($macd['hist']) && is_numeric($macd['hist'])) {
                            $values['macd_histogram'] = (string) $macd['hist'];
                        }
                    } elseif (is_numeric($macd)) {
                        $values['macd'] = (string) $macd;
                    }
                }
                if (isset($context['ema']) && is_array($context['ema'])) {
                    foreach ([20, 50, 200] as $period) {
                        if (isset($context['ema'][(string) $period]) && is_numeric($context['ema'][(string) $period])) {
                            $values['ema' . $period] = (string) $context['ema'][(string) $period];
                        } elseif (isset($context['ema'][$period]) && is_numeric($context['ema'][$period])) {
                            $values['ema' . $period] = (string) $context['ema'][$period];
                        }
                    }
                }
                if (isset($context['bollinger']) && is_array($context['bollinger'])) {
                    $boll = $context['bollinger'];
                    if (isset($boll['upper']) && is_numeric($boll['upper'])) {
                        $values['bb_upper'] = (string) $boll['upper'];
                    }
                    if (isset($boll['middle']) && is_numeric($boll['middle'])) {
                        $values['bb_middle'] = (string) $boll['middle'];
                    }
                    if (isset($boll['lower']) && is_numeric($boll['lower'])) {
                        $values['bb_lower'] = (string) $boll['lower'];
                    }
                }
                if (isset($context['adx'])) {
                    $adx = $context['adx'];
                    if (is_array($adx)) {
                        $val = $adx['14'] ?? null;
                        if (is_numeric($val)) {
                            $values['adx'] = (string) $val;
                        }
                    } elseif (is_numeric($adx)) {
                        $values['adx'] = (string) $adx;
                    }
                }
                foreach (['ma9', 'ma21'] as $maKey) {
                    if (isset($context[$maKey]) && is_numeric($context[$maKey])) {
                        $values[$maKey] = (string) $context[$maKey];
                    }
                }
                if (isset($context['close']) && is_numeric($context['close'])) {
                    $values['close'] = (string) $context['close'];
                }
            }

            if (isset($result['atr']) && is_numeric($result['atr'])) {
                $values['atr'] = (string) $result['atr'];
            }

            $meta = [];
            if (isset($values['meta']) && is_array($values['meta'])) {
                $meta = $values['meta'];
            }
            $meta['origin'] = 'mtf_service';
            if ($runId !== null) {
                $meta['run_id'] = $runId;
            }
            $values['meta'] = $meta;

            $this->mtfLogger->debug('[MTF] Indicator snapshot prepared', [
                'symbol' => strtoupper($symbol),
                'timeframe' => $tf,
                'kline_time' => $klineTime->format('Y-m-d H:i:s'),
                'run_id' => $runId,
                'status' => $result['status'] ?? null,
                'values_keys' => array_keys($values),
            ]);

            $this->messageBus->dispatch(new IndicatorSnapshotProjectionMessage(
                strtoupper($symbol),
                $tf,
                $klineTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $values,
                'MTF_SERVICE',
                $runId,
            ));

            $this->mtfLogger->info('[MTF] Indicator snapshot queued', [
                'symbol' => strtoupper($symbol),
                'timeframe' => $tf,
                'kline_time' => $klineTime->format('Y-m-d H:i:s'),
                'values_count' => count($values),
            ]);
        } catch (\Throwable $e) {
            $this->mtfLogger->debug('[MTF] Indicator snapshot persist failed', ['symbol' => $symbol, 'tf' => $tf, 'error' => $e->getMessage()]);
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
        $this->mtfLogger->info('[MTF] Starting MTF cycle', ['run_id' => $runId->toString()]);

        $results = [];
        $now = $this->timeService->getCurrentAlignedUtc();

        // Vérifier le kill switch global
        if (!$this->mtfSwitchRepository->isGlobalSwitchOn()) {
            $this->mtfLogger->warning('[MTF] Global kill switch is OFF, skipping cycle');
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
            $this->mtfLogger->warning('[MTF] No active symbols found');
            $this->auditStep($runId, 'GLOBAL', 'NO_ACTIVE_SYMBOLS', 'No active symbols found');
            yield [
                'symbol' => 'GLOBAL',
                'result' => ['status' => 'SKIPPED', 'reason' => 'No active symbols found'],
                'progress' => ['current' => 0, 'total' => 0, 'percentage' => 0, 'symbol' => 'GLOBAL', 'status' => 'SKIPPED']
            ];
            return ['status' => 'SKIPPED', 'reason' => 'No active symbols found'];
        }

        $this->mtfLogger->info('[MTF] Processing symbols', [
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
                $this->mtfLogger->error('[MTF] Error processing symbol', [
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

        $this->mtfLogger->info('[MTF] MTF cycle completed', [
            'run_id' => $runId->toString(),
            'results' => $results
        ]);

        // Construire un résumé harmonisé (sans entrée synthétique 'FINAL')
        $processedCount = count($results);
        $successfulCount = count(array_filter($results, function ($r) {
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['SUCCESS', 'COMPLETED', 'READY'], true);
        }));
        $failedCount = count(array_filter($results, function ($r) {
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['ERROR', 'INVALID'], true);
        }));
        $skippedCount = count(array_filter($results, function ($r) {
            $td = $r['trading_decision']['status'] ?? null;
            if (is_string($td) && strtolower($td) === 'skipped') { return true; }
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['SKIPPED', 'GRACE_WINDOW'], true);
        }));

        $summary = [
            'status' => 'completed',
            'total_symbols' => $totalSymbols,
            'processed_symbols' => $processedCount,
            'successful_symbols' => $successfulCount,
            'error_symbols' => $failedCount,
            'skipped_symbols' => $skippedCount,
        ];

        // Yield le résumé final dans un bloc dédié
        yield [
            'summary' => $summary,
            'results' => $results,
        ];

        return ['summary' => $summary, 'results' => $results];
    }

    /**
     * Traite un symbole spécifique selon la logique MTF
     * @param MtfValidationConfig|null $config Config à utiliser (si null, utilise le config par défaut)
     */
private function processSymbol(string $symbol, UuidInterface $runId, \DateTimeImmutable $now, ?string $currentTf = null, bool $forceTimeframeCheck = false, bool $forceRun = false, bool $skipContextValidation = false, ?MtfValidationConfig $config = null): array
    {
        $this->mtfLogger->debug('[MTF] Processing symbol', ['symbol' => $symbol]);

        // Vérifier le kill switch du symbole (sauf si force-run est activé)
        if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbol($symbol)) {
            $this->mtfLogger->debug('[MTF] Symbol kill switch is OFF', ['symbol' => $symbol, 'force_run' => $forceRun]);
            $this->auditStep($runId, $symbol, 'KILL_SWITCH_OFF', 'Symbol kill switch is OFF');
            return ['status' => 'SKIPPED', 'reason' => 'Symbol kill switch OFF', 'blocking_tf' => 'symbol'];
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
                $this->persistIndicatorSnapshot($symbol, $currentTf, $result, $runId->toString());
            } catch (\Throwable) {
                // best-effort
            }
            if ($this->isGraceWindowResult($result)) {
                return $result + ['blocking_tf' => $currentTf];
            }

            if (($result['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, strtoupper($currentTf) . '_VALIDATION_FAILED', $result['reason'] ?? "$currentTf validation failed", [
                    'from_cache' => (bool)($result['from_cache'] ?? false),
                ]);
                return $result + ['blocking_tf' => $currentTf];
            }

            // Mettre à jour l'état pour le timeframe spécifique
            $timeframeService->updateState($symbol, $result);

            // Best-effort: protéger le flush en cas d'EntityManager fermé
            try {
                $em = $this->entityManager;
                $isOpen = true;
                try {
                    if (method_exists($em, 'isOpen')) {
                        $isOpen = (bool) $em->isOpen();
                    }
                } catch (\Throwable) {
                    $isOpen = true;
                }
                if ($isOpen) {
                    $em->flush();
                } else {
                    $this->mtfLogger->warning('[MTF] EntityManager closed during single TF flush; skipping', [
                        'symbol' => $symbol,
                        'timeframe' => $currentTf,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->mtfLogger->warning('[MTF] Failed to flush state after single TF update (best-effort)', [
                    'symbol' => $symbol,
                    'timeframe' => $currentTf,
                    'error' => $e->getMessage(),
                ]);
            }

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
        $activeConfig = $config ?? $this->mtfValidationConfig;
        $cfg = $activeConfig->getConfig();
        $mtfCfg = $cfg['mtf_validation'] ?? $cfg;
        $startFrom = strtolower((string)($cfg['validation']['start_from_timeframe'] ?? '4h'));
        $contextTimeframes = array_map('strtolower', (array)($mtfCfg['context_timeframes'] ?? []));
        $executionTimeframes = array_map('strtolower', (array)($mtfCfg['execution_timeframes'] ?? []));
        $tfOrder = ['4h','1h','15m','5m','1m'];
        $startIndex = array_search($startFrom, $tfOrder, true);
        if ($startIndex === false) {
            $startIndex = 0;
        }
        $includeFlags = [];
        $contextOnlyFlags = [];
        $shouldRunFlags = [];
        foreach ($tfOrder as $idx => $tfKey) {
            $includeFlags[$tfKey] = $idx >= $startIndex;
            $contextOnlyFlags[$tfKey] = !$includeFlags[$tfKey] && in_array($tfKey, $contextTimeframes, true);
            $shouldRunFlags[$tfKey] = $includeFlags[$tfKey] || $contextOnlyFlags[$tfKey];
        }
        // Inclure uniquement les TF à partir de start_from_timeframe vers le bas (aucun TF supérieur)
        $include4h  = $includeFlags['4h'];
        $include1h  = $includeFlags['1h'];
        $include15m = $includeFlags['15m'];
        $include5m  = $includeFlags['5m'];
        $include1m  = $includeFlags['1m'];
        $contextOnly4h  = $contextOnlyFlags['4h'];
        $contextOnly1h  = $contextOnlyFlags['1h'];
        $contextOnly15m = $contextOnlyFlags['15m'];
        $contextOnly5m  = $contextOnlyFlags['5m'];
        $contextOnly1m  = $contextOnlyFlags['1m'];
        $shouldRun4h  = $shouldRunFlags['4h'];
        $shouldRun1h  = $shouldRunFlags['1h'];
        $shouldRun15m = $shouldRunFlags['15m'];
        $shouldRun5m  = $shouldRunFlags['5m'];
        $shouldRun1m  = $shouldRunFlags['1m'];

        $cacheWarmup = false;
        $cacheWarmupTfs = [];

        $result4h = null;
        if ($shouldRun4h) {
            $tf4hStart = microtime(true);
            $this->mtfLogger->debug('[MTF] Start TF 4h', ['symbol' => $symbol]);
            $hadCache4h = null;
            $cacheStart = microtime(true);
            $cached = $this->getCachedTfResult($symbol, '4h', $hadCache4h);
            $cacheDuration = microtime(true) - $cacheStart;
            if ($hadCache4h === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '4h';
            }
            if ($this->shouldReuseCachedResult($cached, '4h', $symbol)) {
                $this->mtfLogger->debug('[MTF] Cache HIT 4h', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result4h = $cached;
                $this->mtfLogger->info('[MTF] Performance 4h', [
                    'symbol' => $symbol,
                    'timeframe' => '4h',
                    'duration_seconds' => round(microtime(true) - $tf4hStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => true,
                ]);
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
                $this->mtfLogger->info('[MTF] Performance 4h', [
                    'symbol' => $symbol,
                    'timeframe' => '4h',
                    'duration_seconds' => round(microtime(true) - $tf4hStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => false,
                ]);
            }
            // Persister systématiquement un snapshot 4h (même en INVALID)
            $this->persistIndicatorSnapshot($symbol, '4h', $result4h, $runId->toString());
            if ($this->isGraceWindowResult($result4h)) {
                if ($contextOnly4h) {
                    $this->mtfLogger->info('[MTF] Context-only TF 4h in grace window, ignoring blocking', ['symbol' => $symbol]);
                } else {
                    return $result4h + ['blocking_tf' => '4h'];
                }
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
                    'context_only' => $contextOnly4h,
                ]);
                if (!$contextOnly4h) {
                    return $result4h + ['blocking_tf' => '4h'];
                }
            } else {
                $this->timeframe4hService->updateState($symbol, $result4h);
            }
        }

        $result1h = null;
        if ($shouldRun1h) {
            $tf1hStart = microtime(true);
            $hadCache1h = null;
            $cacheStart = microtime(true);
            $cached = $this->getCachedTfResult($symbol, '1h', $hadCache1h);
            $cacheDuration = microtime(true) - $cacheStart;
            if ($hadCache1h === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '1h';
            }
            if ($this->shouldReuseCachedResult($cached, '1h', $symbol)) {
                $this->mtfLogger->debug('[MTF] Cache HIT 1h', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result1h = $cached;
                $this->mtfLogger->info('[MTF] Performance 1h', [
                    'symbol' => $symbol,
                    'timeframe' => '1h',
                    'duration_seconds' => round(microtime(true) - $tf1hStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => true,
                ]);
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
                $this->mtfLogger->info('[MTF] Performance 1h', [
                    'symbol' => $symbol,
                    'timeframe' => '1h',
                    'duration_seconds' => round(microtime(true) - $tf1hStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => false,
                ]);
            }
            // Persister systématiquement un snapshot 1h
            $this->persistIndicatorSnapshot($symbol, '1h', $result1h, $runId->toString());
            if ($this->isGraceWindowResult($result1h)) {
                if ($contextOnly1h) {
                    $this->mtfLogger->info('[MTF] Context-only TF 1h in grace window, ignoring blocking', ['symbol' => $symbol]);
                } else {
                    return $result1h + ['blocking_tf' => '1h'];
                }
            }

            $canUse1h = (strtoupper((string)($result1h['status'] ?? '')) === 'VALID');
            if (!$canUse1h) {
                $this->mtfLogger->info('[MTF] 1h not VALID', ['symbol' => $symbol, 'reason' => $result1h['reason'] ?? null, 'context_only' => $contextOnly1h, 'in_context_tfs' => in_array('1h', $contextTimeframes, true), 'in_exec_tfs' => in_array('1h', $executionTimeframes, true)]);
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
                    'context_only' => $contextOnly1h,
                ]);
                // Ne bloquer que si 1h est dans execution_timeframes (nécessaire pour l'exécution)
                // Si 1h est seulement dans context_timeframes, laisser ContextDecisionService gérer
                if (!$contextOnly1h && in_array('1h', $executionTimeframes, true)) {
                    $this->mtfLogger->info('[MTF] 1h is in execution_timeframes and not VALID, stop cascade', ['symbol' => $symbol]);
                    return $result1h + ['blocking_tf' => '1h'];
                }
                // Si 1h est seulement dans context_timeframes, continuer (ContextDecisionService gérera)
                $this->mtfLogger->info('[MTF] 1h not VALID but only in context_timeframes, continue cascade', ['symbol' => $symbol]);
            }
            if ($canUse1h && $include4h) {
                // Règle: 1h doit matcher 4h si 4h inclus
                $this->mtfLogger->debug('[MTF] Check alignment 1h vs 4h', ['symbol' => $symbol, 'h4' => $result4h['signal_side'] ?? 'NONE', 'h1' => $result1h['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe1hService->checkAlignment($result1h, $result4h, '4H');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->mtfLogger->info('[MTF] Alignment failed 1h vs 4h, stop cascade', ['symbol' => $symbol]);
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
            if ($canUse1h) {
                $this->timeframe1hService->updateState($symbol, $result1h);
            }
        }

        // Étape 15m (seulement si incluse)
        $result15m = null;
        if ($shouldRun15m) {
            $tf15mStart = microtime(true);
            $this->mtfLogger->debug('[MTF] Start TF 15m', ['symbol' => $symbol]);
            $hadCache15m = null;
            $cacheStart = microtime(true);
            $cached = $this->getCachedTfResult($symbol, '15m', $hadCache15m);
            $cacheDuration = microtime(true) - $cacheStart;
            if ($hadCache15m === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '15m';
            }
            if ($this->shouldReuseCachedResult($cached, '15m', $symbol)) {
                $this->mtfLogger->debug('[MTF] Cache HIT 15m', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result15m = $cached;
                $this->mtfLogger->info('[MTF] Performance 15m', [
                    'symbol' => $symbol,
                    'timeframe' => '15m',
                    'duration_seconds' => round(microtime(true) - $tf15mStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => true,
                ]);
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
                $this->mtfLogger->info('[MTF] Performance 15m', [
                    'symbol' => $symbol,
                    'timeframe' => '15m',
                    'duration_seconds' => round(microtime(true) - $tf15mStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => false,
                ]);
            }
            // Persister systématiquement un snapshot 15m
            $this->persistIndicatorSnapshot($symbol, '15m', $result15m, $runId->toString());
            if ($this->isGraceWindowResult($result15m)) {
                if ($contextOnly15m) {
                    $this->mtfLogger->info('[MTF] Context-only TF 15m in grace window, ignoring blocking', ['symbol' => $symbol]);
                } else {
                    return $result15m + ['blocking_tf' => '15m'];
                }
            }

            $canUse15m = (strtoupper((string)($result15m['status'] ?? '')) === 'VALID');
            if (!$canUse15m) {
                $this->mtfLogger->info('[MTF] 15m not VALID', ['symbol' => $symbol, 'reason' => $result15m['reason'] ?? null, 'context_only' => $contextOnly15m, 'in_context_tfs' => in_array('15m', $contextTimeframes, true), 'in_exec_tfs' => in_array('15m', $executionTimeframes, true)]);
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
                    'context_only' => $contextOnly15m,
                ]);
                // Ne bloquer que si 15m est dans execution_timeframes (nécessaire pour l'exécution)
                // Si 15m est seulement dans context_timeframes, laisser ContextDecisionService gérer
                if (!$contextOnly15m && in_array('15m', $executionTimeframes, true)) {
                    // Option de contournement: si autorisé par config, on descend en 5m au lieu d'arrêter la chaîne
                    $allowSkip = (bool)($mtfCfg['allow_skip_lower_tf'] ?? false);
                    if (!($allowSkip && ($include5m ?? false))) {
                        $this->mtfLogger->info('[MTF] 15m is in execution_timeframes and not VALID, stop cascade', ['symbol' => $symbol]);
                        return $result15m + ['blocking_tf' => '15m'];
                    }
                    $this->mtfLogger->info('[MTF] 15m invalid but allow_skip_lower_tf=true, continue with 5m', ['symbol' => $symbol]);
                } else {
                    // Si 15m est seulement dans context_timeframes, continuer (ContextDecisionService gérera)
                    $this->mtfLogger->info('[MTF] 15m not VALID but only in context_timeframes, continue cascade', ['symbol' => $symbol]);
                }
            }
            // Règle: 15m doit matcher 1h si 1h est inclus
            if ($canUse15m && $include1h && is_array($result1h)) {
                $this->mtfLogger->debug('[MTF] Check alignment 15m vs 1h', ['symbol' => $symbol, 'm15' => $result15m['signal_side'] ?? 'NONE', 'h1' => $result1h['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe15mService->checkAlignment($result15m, $result1h, '1H');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->mtfLogger->info('[MTF] Alignment failed 15m vs 1h, stop cascade', ['symbol' => $symbol]);
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
            if ($canUse15m) {
                $this->timeframe15mService->updateState($symbol, $result15m);
            }
        }

        // Étape 5m (seulement si incluse)
        $result5m = null;
        if ($shouldRun5m) {
            $tf5mStart = microtime(true);
            $this->mtfLogger->debug('[MTF] Start TF 5m', ['symbol' => $symbol]);
            $hadCache5m = null;
            $cacheStart = microtime(true);
            $cached = $this->getCachedTfResult($symbol, '5m', $hadCache5m);
            $cacheDuration = microtime(true) - $cacheStart;
            if ($hadCache5m === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '5m';
            }
            if ($this->shouldReuseCachedResult($cached, '5m', $symbol)) {
                $this->mtfLogger->debug('[MTF] Cache HIT 5m', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result5m = $cached;
                $this->mtfLogger->info('[MTF] Performance 5m', [
                    'symbol' => $symbol,
                    'timeframe' => '5m',
                    'duration_seconds' => round(microtime(true) - $tf5mStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => true,
                ]);
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
                $this->mtfLogger->info('[MTF] Performance 5m', [
                    'symbol' => $symbol,
                    'timeframe' => '5m',
                    'duration_seconds' => round(microtime(true) - $tf5mStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => false,
                ]);
            }
            // Calculer ATR 5m (toujours)
            try {
                $result5m['atr'] = $this->computeAtrValue($symbol, '5m');
            } catch (\Throwable $e) {
                $this->mtfLogger->error('[MTF] ATR computation exception', [
                    'symbol' => $symbol,
                    'timeframe' => '5m',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $result5m['atr'] = null;  // Explicitement null au lieu de laisser indéfini
            }

            // Persister systématiquement un snapshot 5m (après calcul ATR)
            $this->persistIndicatorSnapshot($symbol, '5m', $result5m, $runId->toString());
            if ($this->isGraceWindowResult($result5m)) {
                if ($contextOnly5m) {
                    $this->mtfLogger->info('[MTF] Context-only TF 5m in grace window, ignoring blocking', ['symbol' => $symbol]);
                } else {
                    return $result5m + ['blocking_tf' => '5m'];
                }
            }

            $canUse5m = (strtoupper((string)($result5m['status'] ?? '')) === 'VALID');
            if (!$canUse5m) {
                $this->mtfLogger->info('[MTF] 5m not VALID', ['symbol' => $symbol, 'reason' => $result5m['reason'] ?? null, 'context_only' => $contextOnly5m, 'in_context_tfs' => in_array('5m', $contextTimeframes, true), 'in_exec_tfs' => in_array('5m', $executionTimeframes, true)]);
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
                    'context_only' => $contextOnly5m,
                ]);
                // Ne plus bloquer la cascade pour les TF d'exécution
                // Laisser ExecutionTimeframeDecisionService choisir le bon TF d'exécution
                // On continue même si 5m n'est pas VALID, car 1m pourrait être VALID
                $this->mtfLogger->info('[MTF] 5m not VALID, continue cascade (ExecutionTimeframeDecisionService will choose execution TF)', [
                    'symbol' => $symbol,
                    'in_exec_tfs' => in_array('5m', $executionTimeframes, true),
                    'other_exec_tfs' => array_filter($executionTimeframes, fn($tf) => $tf !== '5m'),
                ]);
            }
            // Règle: 5m doit matcher 15m si 15m est inclus
            if ($canUse5m && $include15m && is_array($result15m)) {
                $this->mtfLogger->debug('[MTF] Check alignment 5m vs 15m', ['symbol' => $symbol, 'm5' => $result5m['signal_side'] ?? 'NONE', 'm15' => $result15m['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe5mService->checkAlignment($result5m, $result15m, '15M');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->mtfLogger->info('[MTF] Alignment failed 5m vs 15m, stop cascade', ['symbol' => $symbol]);
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
            if ($canUse5m) {
                $this->timeframe5mService->updateState($symbol, $result5m);
            }
        }

        // Étape 1m (seulement si incluse)
        $result1m = null;
        if ($include1m) {
            $tf1mStart = microtime(true);
            $this->mtfLogger->debug('[MTF] Start TF 1m', ['symbol' => $symbol]);
            $hadCache1m = null;
            $cacheStart = microtime(true);
            $cached = $this->getCachedTfResult($symbol, '1m', $hadCache1m);
            $cacheDuration = microtime(true) - $cacheStart;
            if ($hadCache1m === false) {
                $cacheWarmup = true;
                $cacheWarmupTfs[] = '1m';
            }
            if ($this->shouldReuseCachedResult($cached, '1m', $symbol)) {
                $this->mtfLogger->debug('[MTF] Cache HIT 1m', [
                    'symbol' => $symbol,
                    'status' => $cached['status'] ?? null,
                ]);
                $result1m = $cached;
                $this->mtfLogger->info('[MTF] Performance 1m', [
                    'symbol' => $symbol,
                    'timeframe' => '1m',
                    'duration_seconds' => round(microtime(true) - $tf1mStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => true,
                ]);
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
                $this->mtfLogger->info('[MTF] Performance 1m', [
                    'symbol' => $symbol,
                    'timeframe' => '1m',
                    'duration_seconds' => round(microtime(true) - $tf1mStart, 3),
                    'cache_duration' => round($cacheDuration, 3),
                    'cache_hit' => false,
                ]);
            }
            // Calculer ATR 1m (toujours)
            try {
                $result1m['atr'] = $this->computeAtrValue($symbol, '1m');
            } catch (\Throwable $e) {
                $this->mtfLogger->error('[MTF] ATR computation exception', [
                    'symbol' => $symbol,
                    'timeframe' => '1m',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $result1m['atr'] = null;  // Explicitement null au lieu de laisser indéfini
            }

            // Persister systématiquement un snapshot 1m (après calcul ATR)
            $this->persistIndicatorSnapshot($symbol, '1m', $result1m, $runId->toString());
            if ($this->isGraceWindowResult($result1m)) {
                if ($contextOnly1m) {
                    $this->mtfLogger->info('[MTF] Context-only TF 1m in grace window, ignoring blocking', ['symbol' => $symbol]);
                } else {
                    return $result1m + ['blocking_tf' => '1m'];
                }
            }

            $canUse1m = (strtoupper((string)($result1m['status'] ?? '')) === 'VALID');
            if (!$canUse1m) {
                $this->mtfLogger->info('[MTF] 1m not VALID', ['symbol' => $symbol, 'reason' => $result1m['reason'] ?? null, 'context_only' => $contextOnly1m, 'in_context_tfs' => in_array('1m', $contextTimeframes, true), 'in_exec_tfs' => in_array('1m', $executionTimeframes, true)]);
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
                    'context_only' => $contextOnly1m,
                ]);
                // Ne plus bloquer la cascade pour les TF d'exécution
                // Laisser ExecutionTimeframeDecisionService choisir le bon TF d'exécution
                // On continue même si 1m n'est pas VALID, car 5m pourrait être VALID
                $this->mtfLogger->info('[MTF] 1m not VALID, continue cascade (ExecutionTimeframeDecisionService will choose execution TF)', [
                    'symbol' => $symbol,
                    'in_exec_tfs' => in_array('1m', $executionTimeframes, true),
                    'other_exec_tfs' => array_filter($executionTimeframes, fn($tf) => $tf !== '1m'),
                ]);
            }

            if ($canUse1m) {
                // Log dédié après validation 1m (positions_flow)
                $this->mtfLogger->info('[PositionsFlow] 1m VALIDATED', [
                    'symbol' => $symbol,
                    'signal_side' => $result1m['signal_side'] ?? 'NONE',
                    'kline_time' => isset($result1m['kline_time']) && $result1m['kline_time'] instanceof \DateTimeImmutable ? $result1m['kline_time']->format('Y-m-d H:i:s') : null,
                    'current_price' => $result1m['current_price'] ?? null,
                    'atr' => $result1m['atr'] ?? null,
                ]);
            }
            // Règle: 1m doit matcher 5m si 5m est inclus
            if ($canUse1m && $include5m && is_array($result5m)) {
                $this->mtfLogger->debug('[MTF] Check alignment 1m vs 5m', ['symbol' => $symbol, 'm1' => $result1m['signal_side'] ?? 'NONE', 'm5' => $result5m['signal_side'] ?? 'NONE']);
                $alignmentResult = $this->timeframe1mService->checkAlignment($result1m, $result5m, '5M');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->mtfLogger->info('[MTF] Alignment failed 1m vs 5m, stop cascade', ['symbol' => $symbol]);
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
            if ($canUse1m) {
                $this->timeframe1mService->updateState($symbol, $result1m);
            }
        }

        // Sauvegarder l'état (best-effort)
        try {
            $em = $this->entityManager;
            $isOpen = true;
            try {
                if (method_exists($em, 'isOpen')) {
                    $isOpen = (bool) $em->isOpen();
                }
            } catch (\Throwable) {
                $isOpen = true;
            }
            if ($isOpen) {
                $em->flush();
            } else {
                $this->mtfLogger->warning('[MTF] EntityManager closed during final state flush; skipping', [
                    'symbol' => $symbol,
                ]);
            }
        } catch (\Throwable $e) {
            $this->mtfLogger->warning('[MTF] Failed to flush final state (best-effort)', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }

        // Construire $tfResults depuis les résultats de la cascade
        // Note: La validation de chaîne stricte a été supprimée.
        // Les services ContextDecisionService et ExecutionTimeframeDecisionService
        // valident uniquement les TF configurés dans context_timeframes et execution_timeframes.
        $tfResults = [
            '4h' => is_array($result4h) ? $result4h : null,
            '1h' => is_array($result1h) ? $result1h : null,
            '15m' => is_array($result15m) ? $result15m : null,
            '5m' => is_array($result5m) ? $result5m : null,
            '1m' => is_array($result1m) ? $result1m : null,
        ];

        // Récupérer la config MTF
        $activeConfig = $config ?? $this->mtfValidationConfig;
        $cfg = $activeConfig->getConfig();
        $mtfCfg = $cfg['mtf_validation'] ?? $cfg;
        $contextTimeframes = array_map('strtolower', (array)($mtfCfg['context_timeframes'] ?? []));
        $executionTimeframes = array_map('strtolower', (array)($mtfCfg['execution_timeframes'] ?? []));

        // Construire knownSignals pour contexte (pour buildContextSummary)
        $knownSignals = [];
        foreach ($validationStates as $vs) {
            $knownSignals[$vs['tf']] = ['signal' => strtoupper((string)($vs['signal_side'] ?? 'NONE'))];
        }

        // Log diagnostic : résultats de chaque timeframe avant décision de contexte
        $this->mtfLogger->info('[MTF] Timeframe results before context decision', [
            'symbol' => $symbol,
            'context_timeframes' => $contextTimeframes,
            'execution_timeframes' => $executionTimeframes,
            'tf_results' => array_map(function ($result) {
                if ($result === null || !is_array($result)) {
                    return ['status' => 'NULL_OR_NOT_ARRAY'];
                }
                return [
                    'status' => $result['status'] ?? 'NO_STATUS',
                    'signal_side' => $result['signal_side'] ?? 'NO_SIDE',
                    'reason' => $result['reason'] ?? 'NO_REASON',
                    'blocking_tf' => $result['blocking_tf'] ?? null,
                ];
            }, $tfResults),
        ]);

        // Décision de contexte
        $contextDecision = $this->contextDecisionService->decide($tfResults, $contextTimeframes);

        if (!$skipContextValidation && !$contextDecision->isOk()) {
            $reason = $contextDecision->getReason() ?? 'CONTEXT_NOT_OK';
            $this->mtfLogger->info('[MTF] Context decision failed', [
                'symbol' => $symbol,
                'reason' => $reason,
                'valid_sides' => $contextDecision->getValidSides(),
            ]);
            $this->auditStep($runId, $symbol, 'CONTEXT_REJECTED', sprintf('Context decision failed: %s', $reason), [
                'context_valid_sides' => $contextDecision->getValidSides(),
            ]);
            return [
                'status' => 'INVALID',
                'signal_side' => 'NONE',
                'reason' => $reason,
                'context_side' => null,
                'execution_tf' => null,
            ];
        }

        $contextSide = $contextDecision->getSide() ?? 'NONE';

        // Décision TF d'exécution
        $execDecision = null;
        $executionTf = null;
        if ($contextSide !== 'NONE' && !empty($executionTimeframes)) {
            $execDecision = $this->executionTimeframeDecisionService->decide($tfResults, $executionTimeframes, $contextSide);
            $executionTf = $execDecision->getExecutionTimeframe();
        }

        if ($executionTf === null) {
            $reason = $execDecision?->getReason() ?? 'NO_EXEC_TF';
            $this->mtfLogger->info('[MTF] No execution timeframe aligned with context', [
                'symbol' => $symbol,
                'context_side' => $contextSide,
                'execution_timeframes' => $executionTimeframes,
                'reason' => $reason,
            ]);
            $this->auditStep($runId, $symbol, 'NO_EXEC_TF', 'No execution timeframe aligned with context', [
                'context_side' => $contextSide,
                'execution_timeframes' => $executionTimeframes,
            ]);
            return [
                'status' => 'INVALID',
                'signal_side' => $contextSide,
                'reason' => $reason,
                'context_side' => $contextSide,
                'execution_tf' => null,
            ];
        }

        // Construction résultat READY
        $execRes = $tfResults[$executionTf] ?? [];
        $klineTime = $execRes['kline_time'] ?? null;
        $price = $execRes['current_price'] ?? null;
        $atr = $execRes['atr'] ?? null;
        $indCtx = $execRes['indicator_context'] ?? null;

        $contextSummary = $this->signalValidationService->buildContextSummary($knownSignals, $executionTf, $contextSide);
        $this->mtfLogger->info('[MTF] Context summary', ['symbol' => $symbol, 'execution_tf' => $executionTf, 'context_side' => $contextSide] + $contextSummary);

        // Vérifier si tous les TF utilisés (context + execution) sont en cache
        $usedTfs = array_merge($contextTimeframes, $executionTimeframes);
        $usedTfs = array_unique(array_map('strtolower', $usedTfs));
        $chainFromCache = true;
        foreach ($usedTfs as $tf) {
            $res = $tfResults[$tf] ?? null;
            if ($res === null || !\is_array($res)) {
                continue;
            }
            if (!(($res['from_cache'] ?? false) === true)) {
                $chainFromCache = false;
                break;
            }
        }

        $shouldGrace = $cacheWarmup && !$forceRun;
        if (!$shouldGrace) {
            $auditData = [
                'current_tf' => $executionTf,
                'timeframe' => $executionTf,
                'passed' => $contextSide !== 'NONE',
                'severity' => 0,
                'from_cache' => $chainFromCache,
            ] + $contextSummary;

            $this->auditStep($runId, $symbol, 'MTF_CONTEXT', null, $auditData);
        }

        if ($shouldGrace) {
            $warmupTfs = array_values(array_unique($cacheWarmupTfs));
            $this->mtfLogger->info('[MTF] Cache warm-up detected, skipping trading decision', [
                'symbol' => $symbol,
                'timeframes' => $warmupTfs,
            ]);
            $contextSummaryWarm = $contextSummary;
            $contextSummaryWarm['cache_warmup'] = $warmupTfs;

            return [
                'status' => 'GRACE_WINDOW',
                'signal_side' => 'NONE',
                'context' => $contextSummaryWarm,
                'kline_time' => $klineTime,
                'current_price' => $price,
                'atr' => $atr,
                'indicator_context' => $indCtx,
                'execution_tf' => $executionTf,
                'blocking_tf' => 'cache_warmup',
                'reason' => 'CACHE_WARMUP',
            ];
        }

        return [
            'status' => 'READY',
            'signal_side' => $contextSide,
            'reason' => 'CONTEXT_AND_EXEC_OK',
            'context_side' => $contextSide,
            'execution_tf' => $executionTf,
            'kline_time' => $klineTime,
            'current_price' => $price,
            'atr' => $atr,
            'indicator_context' => $indCtx,
            'context' => $contextSummary,
        ];
    }

    private function computeAtrValue(string $symbol, string $tf): ?float
    {
        // Paramètres par défaut (trading.yml): period=14, method=wilder
        $period = 14;
        $method = 'wilder';
        $tfEnum = Timeframe::from($tf);

        // Attempt 1: Retrieve the klines
        $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 250);

        $this->mtfLogger->debug('[MTF] ATR computation start', [
            'symbol' => $symbol,
            'tf' => $tf,
            'klines_count' => count($klines),
            'period' => $period,
        ]);

        if (empty($klines)) {
            $this->mtfLogger->warning('[MTF] No klines for ATR computation', [
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

        $calc = new \App\Indicator\Core\AtrCalculator($this->mtfLogger);
        $atr = $calc->computeWithRules($ohlc, $period, $method, strtolower($tf));

        // GARDE : Si ATR = 0, réessayer une fois (les klines étaient peut-être en cours d'insertion)
        if ($atr === 0.0) {
            $this->mtfLogger->warning('[TO_BE_DELETED][MTF_ATR_ZERO]', [
                'symbol' => $symbol,
                'tf' => $tf,
                'ohlc_count' => count($ohlc),
            ]);
            $this->mtfLogger->warning('[MTF] ATR = 0.0, retrying klines fetch', [
                'symbol' => $symbol,
                'tf' => $tf,
                'first_attempt_klines' => count($klines),
                'first_candle' => $ohlc[0] ?? null,
                'last_candle' => $ohlc[count($ohlc) - 1] ?? null,
            ]);

            // Attendre 100ms pour laisser les klines s'insérer en DB
            usleep(100000);

            // Tentative 2 : Récupérer les klines à nouveau
            $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 250);

            if (empty($klines)) {
                $this->mtfLogger->error('[MTF] No klines on retry for ATR computation', [
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
                $this->mtfLogger->error('[TO_BE_DELETED][MTF_ATR_ZERO_RETRY]', [
                    'symbol' => $symbol,
                    'tf' => $tf,
                    'retry_klines_count' => count($klines),
                ]);
                $this->mtfLogger->error('[MTF] ATR still 0.0 after retry', [
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

            $this->mtfLogger->info('[MTF] ATR computed successfully on retry', [
                'symbol' => $symbol,
                'tf' => $tf,
                'atr' => $atr,
            ]);
        }

        $this->mtfLogger->debug('[MTF] ATR computation result', [
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
            $this->mtfLogger->warning('[MTF] KlineJsonIngestionService not available, skipping bulk fill');
            return;
        }

        $this->mtfLogger->info('[MTF] Filling missing klines in bulk', [
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
            $this->mtfLogger->warning('[MTF] No klines fetched from BitMart', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value
            ]);
            return;
        }

        // Insertion en masse via la fonction SQL JSON
        $result = $this->klineJsonIngestion->ingestKlinesBatch($fetchedKlines, $symbol, $timeframe->value);

        $this->mtfLogger->info('[MTF] Bulk klines insertion completed', [
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

        // Ajouter le run_id et la timeframe aux détails
        $details = $audit->getDetails();
        $details['run_id'] = $runId->toString();
        if ($audit->getTimeframe() !== null && !isset($details['timeframe'])) {
            $details['timeframe'] = $audit->getTimeframe()->value;
        }
        $audit->setDetails($details);

        // Injecter un trace_id cohérent (par symbole) si disponible
        if ($this->traceIdProvider !== null) {
            $traceId = $this->traceIdProvider->getOrCreate($symbol);
            $audit->setTraceId($traceId);
            // Propager également dans les détails pour les requêtes SQL brutes
            $details = $audit->getDetails();
            if (!isset($details['trace_id'])) {
                $details['trace_id'] = $traceId;
            }
            $audit->setDetails($details);
        }

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

                $this->mtfLogger->info('MTF validation cached', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'status' => $status,
                    'kline_time' => $klineTime->format('Y-m-d H:i:s'),
                    'expiration_minutes' => $expirationMinutes,
                    'expiration_time' => $expirationTime->format('Y-m-d H:i:s')
                ]);
            }
        } catch (\Exception $e) {
            $this->mtfLogger->error('Failed to persist MTF results', [
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
        // Si les providers sont disponibles, essayer chaque mode activé en cascade
        if ($this->mtfValidationConfigProvider !== null && $this->conditionRegistry !== null) {
            $result = $this->processSymbolWithModeFallback($symbol, $runId, $now, $currentTf, $forceTimeframeCheck, $forceRun, $skipContextValidation);
        } else {
            // Fallback vers l'ancien comportement (compatibilité)
            $result = $this->processSymbol($symbol, $runId, $now, $currentTf, $forceTimeframeCheck, $forceRun, $skipContextValidation);
        }

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

    /**
     * Traite un symbole en essayant chaque mode activé jusqu'à ce qu'un passe
     * @return array Résultat avec validation_mode_used et trade_entry_mode_used
     */
    private function processSymbolWithModeFallback(
        string $symbol,
        UuidInterface $runId,
        \DateTimeImmutable $now,
        ?string $currentTf = null,
        bool $forceTimeframeCheck = false,
        bool $forceRun = false,
        bool $skipContextValidation = false
    ): array {
        $enabledModes = $this->mtfValidationConfigProvider->getEnabledModes();

        if (empty($enabledModes)) {
            throw new \RuntimeException(sprintf(
                '[MTF] No enabled modes found for symbol "%s". This is likely a configuration error.',
                $symbol
            ));
        }

        $lastError = null;
        $lastResult = null;

        foreach ($enabledModes as $mode) {
            $modeName = $mode['name'] ?? 'unknown';
            $this->mtfLogger->info('[MTF] Trying mode for symbol', [
                'symbol' => $symbol,
                'mode' => $modeName,
                'priority' => $mode['priority'] ?? 999,
            ]);

            try {
                // Charger les configs pour ce mode
                $mtfConfig = $this->mtfValidationConfigProvider->getConfigForMode($modeName);

                // Recharger le ConditionRegistry avec le nouveau config
                $this->conditionRegistry->reload($mtfConfig);

                // Traiter le symbole avec ce config
                $result = $this->processSymbol(
                    $symbol,
                    $runId,
                    $now,
                    $currentTf,
                    $forceTimeframeCheck,
                    $forceRun,
                    $skipContextValidation,
                    $mtfConfig
                );

                // Vérifier si le résultat est valide (READY, SUCCESS, ou VALID selon le contexte)
                $status = strtoupper((string)($result['status'] ?? 'UNKNOWN'));
                if (in_array($status, ['READY', 'SUCCESS', 'VALID'], true)) {
                    $this->mtfLogger->info('[MTF] Mode succeeded for symbol', [
                        'symbol' => $symbol,
                        'mode' => $modeName,
                        'status' => $status,
                    ]);

                    // Ajouter les informations de mode utilisées
                    $result['validation_mode_used'] = $modeName;
                    if ($this->tradeEntryConfigProvider !== null) {
                        try {
                            $tradeEntryConfig = $this->tradeEntryConfigProvider->getConfigForMode($modeName);
                            $result['trade_entry_mode_used'] = $modeName;
                        } catch (\Throwable $e) {
                            $this->mtfLogger->warning('[MTF] Failed to load TradeEntry config for mode', [
                                'symbol' => $symbol,
                                'mode' => $modeName,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return $result;
                }

                // Le mode a échoué, continuer avec le suivant
                $this->mtfLogger->debug('[MTF] Mode failed for symbol, trying next', [
                    'symbol' => $symbol,
                    'mode' => $modeName,
                    'status' => $status,
                    'reason' => $result['reason'] ?? 'unknown',
                ]);

                $lastError = $result;
                $lastResult = $result;

            } catch (\Throwable $e) {
                $this->mtfLogger->error('[MTF] Error processing symbol with mode', [
                    'symbol' => $symbol,
                    'mode' => $modeName,
                    'error' => $e->getMessage(),
                ]);
                $lastError = [
                    'status' => 'ERROR',
                    'error' => $e->getMessage(),
                    'mode' => $modeName,
                ];
            }
        }

        // Aucun mode n'a réussi, retourner le dernier résultat ou une erreur
        $this->mtfLogger->warning('[MTF] All modes failed for symbol', [
            'symbol' => $symbol,
            'modes_tried' => count($enabledModes),
        ]);

        $finalResult = $lastResult ?? $lastError ?? [
            'status' => 'INVALID',
            'reason' => 'All modes failed',
        ];
        $finalResult['validation_mode_used'] = null;
        $finalResult['trade_entry_mode_used'] = null;

        return $finalResult;
    }

}
