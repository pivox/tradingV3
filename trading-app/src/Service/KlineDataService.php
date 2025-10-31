<?php

namespace App\Service;

use App\Provider\Bitmart\Dto\KlineDto;
use App\Common\Enum\Timeframe;
use Brick\Math\BigDecimal;

class KlineDataService
{
    /**
     * Convertit des données JSON de klines en tableau de KlineDto
     */
    public function parseKlinesFromJson(array $jsonData, string $symbol, Timeframe $timeframe): array
    {
        $klines = [];

        // Format attendu: array d'objets avec open_time, open, high, low, close, volume
        foreach ($jsonData as $klineData) {

            $klines[] = new KlineDto([
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'openTime' => new \DateTimeImmutable($klineData['open_time'], new \DateTimeZone('UTC')),
                'open' => BigDecimal::of($klineData['open']),
                'high' => BigDecimal::of($klineData['high']),
                'low' => BigDecimal::of($klineData['low']),
                'close' => BigDecimal::of($klineData['close']),
                'volume' => BigDecimal::of($klineData['volume']),
                'source' => 'JSON_UPLOAD'
            ]);
        }

        return $klines;
    }

    /**
     * Convertit des klines en format simple pour l'interface
     */
    public function convertKlinesToSimpleFormat(array $klines): array
    {
        $result = [
            'closes' => [],
            'highs' => [],
            'lows' => [],
            'opens' => [],
            'volumes' => [],
            'timestamps' => []
        ];

        foreach ($klines as $kline) {
            $result['closes'][] = $kline->close->toFloat();
            $result['highs'][] = $kline->high->toFloat();
            $result['lows'][] = $kline->low->toFloat();
            $result['opens'][] = $kline->open->toFloat();
            $result['volumes'][] = $kline->volume->toFloat();
            $result['timestamps'][] = $kline->openTime->format('Y-m-d H:i:s');
        }

        return $result;
    }

    /**
     * Valide le format des données JSON de klines
     */
    public function validateKlinesJson(array $jsonData): array
    {
        $errors = [];

        if (empty($jsonData)) {
            $errors[] = 'Les données de klines ne peuvent pas être vides';
            return $errors;
        }

        $requiredFields = ['open_time', 'open', 'high', 'low', 'close', 'volume'];

        foreach ($jsonData as $index => $klineData) {
            if (!is_array($klineData)) {
                $errors[] = "Kline $index: doit être un objet";
                continue;
            }

            foreach ($requiredFields as $field) {
                if (!isset($klineData[$field])) {
                    $errors[] = "Kline $index: champ '$field' manquant";
                } elseif (!is_numeric($klineData[$field]) && $field !== 'open_time') {
                    $errors[] = "Kline $index: champ '$field' doit être numérique";
                }
            }

            // Validation des valeurs OHLC
            if (isset($klineData['open'], $klineData['high'], $klineData['low'], $klineData['close'])) {
                $open = (float) $klineData['open'];
                $high = (float) $klineData['high'];
                $low = (float) $klineData['low'];
                $close = (float) $klineData['close'];

                if ($high < $low) {
                    $errors[] = "Kline $index: high ($high) ne peut pas être inférieur à low ($low)";
                }
                if ($high < $open) {
                    $errors[] = "Kline $index: high ($high) ne peut pas être inférieur à open ($open)";
                }
                if ($high < $close) {
                    $errors[] = "Kline $index: high ($high) ne peut pas être inférieur à close ($close)";
                }
                if ($low > $open) {
                    $errors[] = "Kline $index: low ($low) ne peut pas être supérieur à open ($open)";
                }
                if ($low > $close) {
                    $errors[] = "Kline $index: low ($low) ne peut pas être supérieur à close ($close)";
                }
            }

            // Validation de la date
            if (isset($klineData['open_time'])) {
                try {
                    new \DateTimeImmutable($klineData['open_time']);
                } catch (\Exception $e) {
                    $errors[] = "Kline $index: format de date invalide pour open_time";
                }
            }
        }

        return $errors;
    }

    /**
     * Génère un exemple de format JSON pour les klines
     */
    public function getExampleKlinesJson(): array
    {
        return [
            [
                'open_time' => '2024-01-01 00:00:00',
                'open' => 50000.0,
                'high' => 50100.0,
                'low' => 49900.0,
                'close' => 50050.0,
                'volume' => 1000.0
            ],
            [
                'open_time' => '2024-01-01 01:00:00',
                'open' => 50050.0,
                'high' => 50200.0,
                'low' => 50000.0,
                'close' => 50150.0,
                'volume' => 1200.0
            ],
            [
                'open_time' => '2024-01-01 02:00:00',
                'open' => 50150.0,
                'high' => 50300.0,
                'low' => 50100.0,
                'close' => 50250.0,
                'volume' => 1100.0
            ]
        ];
    }

    /**
     * Vérifie si les klines ont suffisamment de données pour les indicateurs
     */
    public function hasEnoughData(array $klines, int $minRequired = 50): bool
    {
        return count($klines) >= $minRequired;
    }

    /**
     * Extrait les données OHLCV des klines pour les indicateurs
     */
    public function extractOhlcvData(array $klines): array
    {
        $closes = [];
        $highs = [];
        $lows = [];
        $opens = [];
        $volumes = [];

        foreach ($klines as $kline) {
            $closes[] = $kline->close->toFloat();
            $highs[] = $kline->high->toFloat();
            $lows[] = $kline->low->toFloat();
            $opens[] = $kline->open->toFloat();
            $volumes[] = $kline->volume->toFloat();
        }

        return [
            'closes' => $closes,
            'highs' => $highs,
            'lows' => $lows,
            'opens' => $opens,
            'volumes' => $volumes
        ];
    }
}

