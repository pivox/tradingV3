<?php

declare(strict_types=1);

namespace App\Provider\Bitmart;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\Repository\KlineRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
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
        private readonly LoggerInterface $logger
    ) {}

    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 499): array
    {
        try {
            $step = $this->convertTimeframeToStep($timeframe);
            $klinesData = $this->bitmartClient->getFuturesKlines(
                symbol: $symbol,
                step: $step,
                limit: $limit
            );

            $klines = [];
            foreach ($klinesData->toArray() as $klineData) {
                $klines[] = new KlineDto(
                    $symbol,
                    $timeframe,
                    new \DateTimeImmutable('@' . $klineData->timestamp),
                    \Brick\Math\BigDecimal::of($klineData->open),
                    \Brick\Math\BigDecimal::of($klineData->high),
                    \Brick\Math\BigDecimal::of($klineData->low),
                    \Brick\Math\BigDecimal::of($klineData->close),
                    \Brick\Math\BigDecimal::of($klineData->volume)
                );
            }

            return $klines;
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
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des klines", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            return [];
        }
        return [];
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

            return $this->bitmartClient->getFuturesKlines($symbol, $step, $startTs, $endTs, $limit)->toArray();
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

    public function getLastKline(string $symbol, Timeframe $timeframe): ?KlineDto
    {
        try {
            $kline = $this->klineRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);
            if (!$kline) {
                return null;
            }

            return new KlineDto(
                $kline->getSymbol(),
                $timeframe,
                $kline->getOpenTime(),
                \Brick\Math\BigDecimal::of($kline->getOpenPriceFloat()),
                \Brick\Math\BigDecimal::of($kline->getHighPriceFloat()),
                \Brick\Math\BigDecimal::of($kline->getLowPriceFloat()),
                \Brick\Math\BigDecimal::of($kline->getClosePriceFloat()),
                \Brick\Math\BigDecimal::of($kline->getVolumeFloat())
            );
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération de la dernière kline", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function saveKline(KlineDto $kline): void
    {
        try {
            $this->klineRepository->saveKline($kline);
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
