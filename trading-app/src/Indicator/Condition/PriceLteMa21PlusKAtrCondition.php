<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;


#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: PriceLteMa21PlusKAtrCondition::NAME)]
#[AsIndicatorCondition(timeframes: ['1h', '15m', '5m', '1m'], name: PriceLteMa21PlusKAtrCondition::NAME)]
final class PriceLteMa21PlusKAtrCondition extends AbstractCondition
{
    public const NAME = 'price_lte_ma21_plus_k_atr';
    private const EPS = 1.0e-8;

    public function __construct(
        #[Autowire(service: 'monolog.logger.mtf')]
        private readonly LoggerInterface $mtfLogger,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $tf = isset($context['timeframe']) ? strtolower((string)$context['timeframe']) : null;
        $symbol = $context['symbol'] ?? null;

        $close = $context['close'] ?? null;
        $level = $context['ma_21_plus_k_atr']
            ?? $context['ma_21_plus_1.3atr']
            ?? $context['ma_21_plus_2atr']
            ?? null;

        if (!\is_float($close) || !\is_float($level)) {
            $meta = $this->baseMeta($context, [
                'missing_data' => true,
                'has_close' => \is_float($close),
                'has_level' => \is_float($level),
            ]);

            if ($tf === '1h') {
                // Sur 1h : ne pas faire échouer le filtre en masse si les données manquent
                $this->mtfLogger->info('[MtfValidation] price_lte_ma21_plus_k_atr missing data on 1h, soft-pass filter', [
                    'symbol' => $symbol,
                    'timeframe' => $tf,
                    'has_close' => $meta['has_close'],
                    'has_level' => $meta['has_level'],
                ]);

                return $this->result(self::NAME, true, null, null, $meta);
            }

            // Autres timeframes (15m/5m/1m) : échec normal du filtre
            $this->mtfLogger->info('[MtfValidation] price_lte_ma21_plus_k_atr missing data', [
                'symbol' => $symbol,
                'timeframe' => $tf,
                'has_close' => $meta['has_close'],
                'has_level' => $meta['has_level'],
            ]);

            return $this->result(self::NAME, false, null, null, $meta);
        }

        $passed = $close <= $level * (1.0 + self::EPS);
        $value  = $close - $level;

        return $this->result(self::NAME, $passed, $value, 0.0, $this->baseMeta($context, [
            'close' => $close,
            'level' => $level,
        ]));
    }
}
