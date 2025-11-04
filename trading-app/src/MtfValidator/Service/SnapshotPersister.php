<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Common\Enum\Timeframe;
use App\Entity\IndicatorSnapshot;
use App\MtfValidator\Support\KlineTimeParser;
use App\Repository\IndicatorSnapshotRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SnapshotPersister
{
    public function __construct(
        private readonly IndicatorSnapshotRepository $snapshotRepository,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
        private readonly KlineTimeParser $klineTimeParser,
    ) {
    }

    public function persist(string $symbol, string $timeframe, array $result): void
    {
        if (strtoupper((string)($result['status'] ?? '')) === 'GRACE_WINDOW') {
            return;
        }

        try {
            $klineTime = $this->klineTimeParser->parse($result['kline_time'] ?? null);
            if (!$klineTime instanceof \DateTimeImmutable) {
                return;
            }

            $values = $this->extractValues($result);
            $snapshot = (new IndicatorSnapshot())
                ->setSymbol(strtoupper($symbol))
                ->setTimeframe(Timeframe::from($timeframe))
                ->setKlineTime($klineTime->setTimezone(new \DateTimeZone('UTC')))
                ->setValues($values)
                ->setSource('PHP');

            $existing = $this->snapshotRepository->findOneBy([
                'symbol' => $snapshot->getSymbol(),
                'timeframe' => $snapshot->getTimeframe(),
                'klineTime' => $snapshot->getKlineTime(),
            ]);

            $this->snapshotRepository->upsert($snapshot);

            $this->logger->info('[MTF] Indicator snapshot persisted', [
                'symbol' => strtoupper($symbol),
                'timeframe' => $timeframe,
                'kline_time' => $klineTime->format('Y-m-d H:i:s'),
                'values_count' => count($values),
                'source' => 'PHP',
                'action' => $existing ? 'update' : 'insert',
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('[MTF] Indicator snapshot persist failed', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, string|float>
     */
    private function extractValues(array $result): array
    {
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

        return $values;
    }
}
