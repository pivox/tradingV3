<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Provider\Bitmart\Dto\KlineDto;
use App\Common\Enum\Timeframe;
use App\Entity\Kline;
use App\Contract\Provider\KlineProviderInterface;
use App\Repository\KlineRepository;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class MtfBackfillService
{
    private const MAX_CANDLES_PER_REQUEST = 500;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAYS = [1000, 2000, 4000]; // milliseconds

    public function __construct(
        private readonly KlineProviderInterface $klineProvider,
        private readonly KlineRepository $klineRepository,
        private readonly MtfTimeService $timeService,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * Effectue le backfill pour un symbole et un timeframe
     */
    public function backfillTimeframe(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $this->logger->info('[Backfill] Starting backfill', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s')
        ]);

        $results = [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'from' => $from,
            'to' => $to,
            'klines_fetched' => 0,
            'klines_upserted' => 0,
            'errors' => [],
            'chunks_processed' => 0
        ];

        // Découper en fenêtres de 500 bougies max
        $windows = $this->timeService->getBackfillWindow($from, $to, $timeframe);
        $chunks = [];

        foreach ($windows as $window) {
            $chunks = array_merge($chunks, $this->timeService->chunkBackfillWindow($window, $timeframe, self::MAX_CANDLES_PER_REQUEST));
        }

        foreach ($chunks as $chunk) {
            try {
                $chunkResult = $this->processChunk($symbol, $timeframe, $chunk);
                $results['klines_fetched'] += $chunkResult['klines_fetched'];
                $results['klines_upserted'] += $chunkResult['klines_upserted'];
                $results['chunks_processed']++;

                if (!empty($chunkResult['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $chunkResult['errors']);
                }

            } catch (\Exception $e) {
                $error = [
                    'chunk' => $chunk,
                    'error' => $e->getMessage(),
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ];
                $results['errors'][] = $error;
                $this->logger->error('[Backfill] Chunk processing failed', $error);
            }
        }

        $this->logger->info('[Backfill] Backfill completed', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'klines_fetched' => $results['klines_fetched'],
            'klines_upserted' => $results['klines_upserted'],
            'chunks_processed' => $results['chunks_processed'],
            'errors_count' => count($results['errors'])
        ]);

        return $results;
    }

    /**
     * Traite un chunk de données
     */
    private function processChunk(string $symbol, Timeframe $timeframe, array $chunk): array
    {
        $result = [
            'klines_fetched' => 0,
            'klines_upserted' => 0,
            'errors' => []
        ];


        // Récupérer les klines via l'API
        $klines = $this->fetchKlinesWithRetry($symbol, $timeframe, $chunk['start'], $chunk['end']);
        $result['klines_fetched'] = count($klines);

        // UPSERT les klines en base
        foreach ($klines as $klineDto) {
            try {
                $this->upsertKline($klineDto);
                $result['klines_upserted']++;
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'kline' => $klineDto->toArray(),
                    'error' => $e->getMessage()
                ];
                $this->logger->error('[Backfill] Kline upsert failed', [
                    'kline' => $klineDto->toArray(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $result;
    }

    /**
     * Récupère les klines avec retry
     */
    private function fetchKlinesWithRetry(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): array {
        $lastException = null;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $this->logger->debug('[Backfill] Fetching klines', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $end->format('Y-m-d H:i:s'),
                    'attempt' => $attempt + 1
                ]);

                // Utiliser le provider de klines
                $klines = $this->klineProvider->getKlinesInWindow(
                    $symbol,
                    $timeframe,
                    $start,
                    $end,
                    self::MAX_CANDLES_PER_REQUEST
                );

                return $klines;

            } catch (\Exception $e) {
                $lastException = $e;
                $this->logger->warning('[Backfill] Fetch attempt failed', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);

                // Gérer le code 429 (trop de requêtes)
                if (str_contains($e->getMessage(), '429')) {
                    $this->logger->info('[Backfill] Rate limit hit, sleeping 2 seconds');
                    sleep(2);
                } elseif ($attempt < self::MAX_RETRIES - 1) {
                    // Attendre avant de réessayer
                    $delay = self::RETRY_DELAYS[$attempt] ?? 4000;
                    $this->logger->info('[Backfill] Retrying in ' . $delay . 'ms');
                    usleep($delay * 1000);
                }
            }
        }

        throw new \RuntimeException('Max retries exceeded: ' . $lastException->getMessage(), 0, $lastException);
    }


    /**
     * UPSERT une kline en base
     */
    private function upsertKline(KlineDto $klineDto): void
    {
        $existingKline = $this->klineRepository->findOneBy([
            'symbol' => $klineDto->symbol,
            'timeframe' => $klineDto->timeframe,
            'openTime' => $klineDto->openTime
        ]);

        if ($existingKline) {
            // Mettre à jour la kline existante
            $existingKline->setOpenPrice($klineDto->open);
            $existingKline->setHighPrice($klineDto->high);
            $existingKline->setLowPrice($klineDto->low);
            $existingKline->setClosePrice($klineDto->close);
            $existingKline->setVolume($klineDto->volume);
            $existingKline->setSource($klineDto->source);
            $existingKline->setUpdatedAt($this->clock->now());
        } else {
            // Créer une nouvelle kline
            $kline = new Kline();
            $kline->setSymbol($klineDto->symbol);
            $kline->setTimeframe($klineDto->timeframe);
            $kline->setOpenTime($klineDto->openTime);
            $kline->setOpenPrice($klineDto->open);
            $kline->setHighPrice($klineDto->high);
            $kline->setLowPrice($klineDto->low);
            $kline->setClosePrice($klineDto->close);
            $kline->setVolume($klineDto->volume);
            $kline->setSource($klineDto->source);

            $this->klineRepository->getEntityManager()->persist($kline);
        }

        $this->klineRepository->getEntityManager()->flush();
    }

    /**
     * Détecte les trous dans les klines et les comble
     */
    public function detectAndFillGaps(string $symbol, Timeframe $timeframe, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $this->logger->info('[Backfill] Detecting gaps', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s')
        ]);

        $gaps = [];
        $stepSeconds = $timeframe->getStepInSeconds();
        $current = $this->timeService->alignTimeframe($from, $timeframe);
        $end = $this->timeService->alignTimeframe($to, $timeframe);

        while ($current < $end) {
            $kline = $this->klineRepository->findOneBy([
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'openTime' => $current
            ]);

            if (!$kline) {
                $gaps[] = $current;
            }

            $current = $current->modify("+{$stepSeconds} seconds");
        }

        if (!empty($gaps)) {
            $this->logger->info('[Backfill] Found gaps', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'gaps_count' => count($gaps),
                'gaps' => array_map(fn($gap) => $gap->format('Y-m-d H:i:s'), $gaps)
            ]);

            // Grouper les gaps en plages continues
            $gapRanges = $this->groupGapsIntoRanges($gaps, $timeframe);

            foreach ($gapRanges as $range) {
                $this->backfillTimeframe($symbol, $timeframe, $range['start'], $range['end']);
            }
        }

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'gaps_found' => count($gaps),
            'gaps' => $gaps
        ];
    }

    /**
     * Groupe les gaps en plages continues
     */
    private function groupGapsIntoRanges(array $gaps, Timeframe $timeframe): array
    {
        if (empty($gaps)) {
            return [];
        }

        sort($gaps);
        $ranges = [];
        $currentRange = ['start' => $gaps[0], 'end' => $gaps[0]];
        $stepSeconds = $timeframe->getStepInSeconds();

        for ($i = 1; $i < count($gaps); $i++) {
            $expectedNext = $currentRange['end']->modify("+{$stepSeconds} seconds");

            if ($gaps[$i]->getTimestamp() === $expectedNext->getTimestamp()) {
                // Gap continu
                $currentRange['end'] = $gaps[$i];
            } else {
                // Nouvelle plage
                $ranges[] = $currentRange;
                $currentRange = ['start' => $gaps[$i], 'end' => $gaps[$i]];
            }
        }

        $ranges[] = $currentRange;
        return $ranges;
    }
}


