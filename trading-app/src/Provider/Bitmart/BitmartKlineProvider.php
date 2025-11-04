<?php

declare(strict_types=1);

namespace App\Provider\Bitmart;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto as ContractKlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Entity\Kline;
use App\Provider\Bitmart\Dto\KlineDto as BitmartRawKlineDto;
use App\Provider\Bitmart\Dto\ListKlinesDto;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\Repository\KlineRepository;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Validator\Constraints\Timezone;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Provider Bitmart pour les klines
 */
#[AsAlias(id: KlineProviderInterface::class)]
final class BitmartKlineProvider implements KlineProviderInterface
{
    public function __construct(
        private readonly BitmartHttpClientPublic $bitmartClient,
        private readonly KlineRepository $klineRepository,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {}

    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 499): array
    {
        $dbKlines = $this->klineRepository->getKlines($symbol, $timeframe, $limit);

        if ($this->isDatasetFresh($dbKlines, $timeframe, $limit)) {
            return $this->mapEntitiesToDtos($dbKlines);
        }

        try {
            $step = $this->convertTimeframeToStep($timeframe);
            $klinesData = $this->bitmartClient->getFuturesKlines(
                symbol: $symbol,
                step: $step,
                limit: $limit
            );

            // Assurer un ordre chronologique ascendant (openTime croissant) avant mapping
            $klinesDataAsc = $klinesData->sortByOpenTimeAsc();
            $klines = $this->mapFetchedKlines($klinesDataAsc, $symbol, $timeframe);

            if (!empty($klines)) {
                $this->klineRepository->upsertKlines($klines);
                return $klines;
            }
        } catch (ServerExceptionInterface $e) {
            $this->logger->error("Erreur serveur lors de la récupération des klines", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
        } catch (TimeoutExceptionInterface $e) {
            $this->logger->error("Timeout lors de la récupération des klines", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error("Erreur de transport lors de la récupération des klines", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des klines", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
        }

        return $this->mapEntitiesToDtos($dbKlines);
    }

    public function getKlinesInWindow(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit = 500
    ): array {
        try {
            $step = $this->convertTimeframeToStep($timeframe);
            $startTs = $start->getTimestamp();
            $endTs = $end->getTimestamp();

            // Normaliser l'ordre ASC par openTime pour les consommateurs d'API
            return $this->bitmartClient
                ->getFuturesKlines($symbol, $step, $startTs, $endTs, $limit)
                ->sortByOpenTimeAsc()
                ->toArray();
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des klines dans la fenêtre", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getLastKline(string $symbol, Timeframe $timeframe): ?ContractKlineDto
    {
        try {
            $kline = $this->klineRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);
            if (!$kline) {
                return null;
            }

            return $this->mapEntityToDto($kline);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération de la dernière kline", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function saveKline(ContractKlineDto $kline): void
    {
        try {
            $this->klineRepository->upsertKlines([$kline]);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la sauvegarde d'une kline", [
                'symbol' => $kline->symbol,
                'timeframe' => $kline->timeframe->value,
                'openTime' => $kline->openTime->format('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ]);
        }
    }

    public function saveKlines(array $klines, string $symbol, Timeframe $timeframe): void
    {
        try {
            $this->klineRepository->upsertKlines($klines);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la sauvegarde des klines", [
                'count' => count($klines),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Transforme les données Bitmart en DTO contract.
     *
     * @return ContractKlineDto[]
     */
    private function mapFetchedKlines(ListKlinesDto $klinesData, string $symbol, Timeframe $timeframe): array
    {
        $klines = [];

        foreach ($klinesData->toArray() as $klineData) {
            if ($klineData instanceof ContractKlineDto) {
                $klines[] = $klineData;
                continue;
            }

            if (!$klineData instanceof BitmartRawKlineDto) {
                continue;
            }

            $klines[] = new ContractKlineDto(
                $symbol,
                $timeframe,
                $klineData->openTime,
                $klineData->open,
                $klineData->high,
                $klineData->low,
                $klineData->close,
                $klineData->volume,
                $klineData->source
            );
        }

        return $klines;
    }

    /**
     * @param Kline[] $klines
     * @return ContractKlineDto[]
     */
    private function mapEntitiesToDtos(array $klines): array
    {
        if (empty($klines)) {
            return [];
        }

        $ordered = array_reverse($klines);

        return array_map(fn(Kline $kline): ContractKlineDto => $this->mapEntityToDto($kline), $ordered);
    }

    private function mapEntityToDto(Kline $kline): ContractKlineDto
    {
        return new ContractKlineDto(
            $kline->getSymbol(),
            $kline->getTimeframe(),
            $kline->getOpenTime(),
            $kline->getOpenPrice(),
            $kline->getHighPrice(),
            $kline->getLowPrice(),
            $kline->getClosePrice(),
            $kline->getVolume() ?? BigDecimal::of('0'),
            $kline->getSource()
        );
    }

    private function isDatasetFresh(array $klines, Timeframe $timeframe, int $limit): bool
    {
        if (empty($klines) || count($klines) < $limit) {
            return false;
        }

        if (!$this->hasContinuousSeries($klines, $timeframe)) {
            return false;
        }

        $expected = $this->expectedLastOpenTime($timeframe);
        $latest = $klines[0]->getOpenTime();

        return $latest->getTimestamp() === $expected->getTimestamp();
    }

    /**
     * @param Kline[] $klines
     */
    private function hasContinuousSeries(array $klines, Timeframe $timeframe): bool
    {
        if (count($klines) <= 1) {
            return true;
        }

        $stepSeconds = $this->convertTimeframeToStep($timeframe) * 60;

        for ($i = 0, $max = count($klines) - 1; $i < $max; $i++) {
            $currentTs = $klines[$i]->getOpenTime()->getTimestamp();
            $nextTs = $klines[$i + 1]->getOpenTime()->getTimestamp();

            if (($currentTs - $nextTs) !== $stepSeconds) {
                return false;
            }
        }

        return true;
    }

    private function expectedLastOpenTime(Timeframe $timeframe): \DateTimeImmutable
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        // Durée en secondes par timeframe
        $seconds = match ($timeframe->value) {
            '4h' => 4 * 3600,
            '1h' => 3600,
            '15m' => 15 * 60,
            '5m' => 5 * 60,
            '1m' => 60,
            default => throw new \InvalidArgumentException('Timeframe non supporté: ' . $timeframe->value),
        };

        // On arrondit l'heure actuelle à la dernière borne du timeframe
        $timestamp = (int) floor($now->getTimestamp() / $seconds) * $seconds;

        //  On retire un intervalle pour viser la dernière bougie FERMÉE
       $timestamp -= $seconds;

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($now->getTimezone());
    }


    public function hasGaps(string $symbol, Timeframe $timeframe): bool
    {
        try {
            $endTime = new \DateTimeImmutable();
            $startTime = $endTime->sub(new \DateInterval('P7D')); // 7 jours en arrière

            $gaps = $this->klineRepository->getMissingKlineChunks($symbol, $timeframe->value, $startTime, $endTime, 1);
            return !empty($gaps);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la vérification des gaps", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getGaps(string $symbol, Timeframe $timeframe): array
    {
        try {
            $endTime = new \DateTimeImmutable();
            $startTime = $endTime->sub(new \DateInterval('P7D')); // 7 jours en arrière

            return $this->klineRepository->getMissingKlineChunks($symbol, $timeframe->value, $startTime, $endTime, 100);
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des gaps", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function healthCheck(): bool
    {
        try {
            return $this->bitmartClient->healthCheck();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'Bitmart';
    }

    private function convertTimeframeToStep(Timeframe $timeframe): int
    {
        return match($timeframe) {
            Timeframe::TF_1M => 1,
            Timeframe::TF_5M => 5,
            Timeframe::TF_15M => 15,
            Timeframe::TF_30M => 30,
            Timeframe::TF_1H => 60,
            Timeframe::TF_4H => 240,
            Timeframe::TF_1D => 1440,
            default => 60
        };
    }
}
