<?php

declare(strict_types=1);

namespace App\Indicator\Controller;

use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/indicators')]
final class IndicatorApiController extends AbstractController
{
    public function __construct(
        private readonly IndicatorProviderInterface $indicatorProvider,
        private readonly KlineProviderInterface $klineProvider,
    ) {
    }

    #[Route('/available', name: 'api_indicators_available', methods: ['GET'])]
    public function listAvailable(): JsonResponse
    {
        return $this->json([
            'indicators' => $this->indicatorProvider->listAvailableIndicators(),
        ]);
    }

    #[Route('/pivots', name: 'api_indicator_pivots', methods: ['GET'])]
    public function getPivots(Request $request): JsonResponse
    {
        $symbol = trim((string) $request->query->get('symbol', ''));
        $timeframe = trim((string) $request->query->get('timeframe', ''));

        if ($symbol === '' || $timeframe === '') {
            return $this->json(['error' => 'Les paramètres symbol et timeframe sont requis.'], 400);
        }

        try {
            $tfEnum = Timeframe::from($timeframe);
        } catch (\ValueError) {
            return $this->json(['error' => sprintf('Timeframe invalide: %s', $timeframe)], 400);
        }

        $dto = $this->indicatorProvider->getListPivot(symbol: $symbol, tf: $tfEnum->value);
        if ($dto === null) {
            return $this->json(['error' => 'Impossible de calculer les points pivots pour ce couple symbol/timeframe.'], 404);
        }

        $payload = $dto->toArray()['pivot_levels'] ?? null;
        if ($payload === null) {
            return $this->json(['error' => 'Aucun point pivot disponible pour cette requête.'], 404);
        }

        $response = [
            'symbol' => $symbol,
            'timeframe' => $tfEnum->value,
            'pivot_levels' => $payload,
        ];

        // Vérifier si on doit inclure les klines
        $withKlines = (int) $request->query->get('with-k', 0);
        if ($withKlines === 1) {
            // Récupérer les klines utilisées pour le calcul (200 comme dans getListPivot)
            $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 200);
            
            if (!empty($klines)) {
                // Formater les klines pour la réponse JSON (même format que /api/klines)
                $klinesFormatted = array_map(function ($kline) {
                    $volume = $kline->volume?->toFloat() ?? 0.0;
                    
                    return [
                        'openTime' => $kline->openTime->getTimestamp() * 1000,
                        'open' => $kline->open->toFloat(),
                        'high' => $kline->high->toFloat(),
                        'low' => $kline->low->toFloat(),
                        'close' => $kline->close->toFloat(),
                        'volume' => $volume,
                        'closeTime' => $kline->openTime->getTimestamp() * 1000,
                        'quoteAssetVolume' => $volume,
                        'numberOfTrades' => 0,
                        'takerBuyBaseAssetVolume' => $volume,
                        'takerBuyQuoteAssetVolume' => $volume,
                    ];
                }, $klines);

                // Trier les klines du plus récent au plus ancien (comme /api/klines)
                usort($klinesFormatted, static function ($a, $b): int {
                    return $b['openTime'] <=> $a['openTime'];
                });

                $response['klines'] = $klinesFormatted;
            }
        }

        return $this->json($response);
    }

    #[Route('/values', name: 'api_indicator_values', methods: ['GET'])]
    public function getIndicators(Request $request): JsonResponse
    {
        $symbol = trim((string) $request->query->get('symbol', ''));
        $timeframe = trim((string) $request->query->get('timeframe', ''));

        if ($symbol === '' || $timeframe === '') {
            return $this->json(['error' => 'Les paramètres symbol et timeframe sont requis.'], 400);
        }

        try {
            $tfEnum = Timeframe::from($timeframe);
        } catch (\ValueError) {
            return $this->json(['error' => sprintf('Timeframe invalide: %s', $timeframe)], 400);
        }

        $dto = $this->indicatorProvider->getListPivot(symbol: $symbol, tf: $tfEnum->value);
        if ($dto === null) {
            return $this->json(['error' => 'Impossible de récupérer les indicateurs pour ce couple symbol/timeframe.'], 404);
        }

        $response = [
            'symbol' => $symbol,
            'timeframe' => $tfEnum->value,
            'indicators' => $dto->toArray(),
            'descriptions' => $dto->getDescriptions(),
        ];

        // Vérifier si on doit inclure les klines
        $withKlines = (int) $request->query->get('with-k', 0);
        if ($withKlines === 1) {
            // Récupérer les klines utilisées pour le calcul (200 comme dans getListPivot)
            $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 200);
            
            if (!empty($klines)) {
                // Formater les klines pour la réponse JSON (même format que /api/klines)
                $klinesFormatted = array_map(function ($kline) {
                    $volume = $kline->volume?->toFloat() ?? 0.0;
                    
                    return [
                        'openTime' => $kline->openTime->getTimestamp() * 1000,
                        'open' => $kline->open->toFloat(),
                        'high' => $kline->high->toFloat(),
                        'low' => $kline->low->toFloat(),
                        'close' => $kline->close->toFloat(),
                        'volume' => $volume,
                        'closeTime' => $kline->openTime->getTimestamp() * 1000,
                        'quoteAssetVolume' => $volume,
                        'numberOfTrades' => 0,
                        'takerBuyBaseAssetVolume' => $volume,
                        'takerBuyQuoteAssetVolume' => $volume,
                    ];
                }, $klines);

                // Trier les klines du plus récent au plus ancien (comme /api/klines)
                usort($klinesFormatted, static function ($a, $b): int {
                    return $b['openTime'] <=> $a['openTime'];
                });

                $response['klines'] = $klinesFormatted;
            }
        }

        return $this->json($response);
    }

    #[Route('/atr', name: 'api_indicator_atr', methods: ['GET'])]
    public function getAtr(Request $request): JsonResponse
    {
        $symbol = trim((string) $request->query->get('symbol', ''));
        if ($symbol === '') {
            return $this->json(['error' => 'Le paramètre symbol est requis.'], 400);
        }

        // Récupérer les timeframes demandés (optionnel, par défaut 1m et 5m)
        $timeframesParam = trim((string) $request->query->get('timeframe', ''));
        $requestedTimeframes = [];
        
        if ($timeframesParam === '') {
            // Comportement par défaut : 1m et 5m
            $requestedTimeframes = [Timeframe::TF_1M->value, Timeframe::TF_5M->value];
        } else {
            // Parser la liste de timeframes séparés par des virgules
            $timeframeStrings = array_map('trim', explode(',', $timeframesParam));
            foreach ($timeframeStrings as $tfStr) {
                if ($tfStr === '') {
                    continue;
                }
                try {
                    $tfEnum = Timeframe::from($tfStr);
                    $requestedTimeframes[] = $tfEnum->value;
                } catch (\ValueError) {
                    return $this->json([
                        'error' => sprintf('Timeframe invalide: %s', $tfStr),
                        'valid_timeframes' => ['1m', '5m', '15m', '30m', '1h', '4h', '1d']
                    ], 400);
                }
            }
        }

        if (empty($requestedTimeframes)) {
            return $this->json(['error' => 'Aucun timeframe valide fourni.'], 400);
        }

        $atrResults = [];
        $klinesResults = [];
        $missing = [];

        foreach ($requestedTimeframes as $tfValue) {
            try {
                $tfEnum = Timeframe::from($tfValue);
                
                // Récupérer les klines (200 pour le calcul ATR)
                $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 200);
                
                if (empty($klines)) {
                    $missing[] = $tfValue;
                    continue;
                }

                // Calculer l'ATR
                $atr = $this->indicatorProvider->getAtr(symbol: $symbol, tf: $tfValue);
                
                if ($atr === null) {
                    $missing[] = $tfValue;
                    continue;
                }

                // Formater les klines pour la réponse JSON (même format que /api/klines)
                $klinesFormatted = array_map(function ($kline) {
                    $volume = $kline->volume?->toFloat() ?? 0.0;
                    
                    return [
                        'openTime' => $kline->openTime->getTimestamp() * 1000,
                        'open' => $kline->open->toFloat(),
                        'high' => $kline->high->toFloat(),
                        'low' => $kline->low->toFloat(),
                        'close' => $kline->close->toFloat(),
                        'volume' => $volume,
                        'closeTime' => $kline->openTime->getTimestamp() * 1000,
                        'quoteAssetVolume' => $volume,
                        'numberOfTrades' => 0,
                        'takerBuyBaseAssetVolume' => $volume,
                        'takerBuyQuoteAssetVolume' => $volume,
                    ];
                }, $klines);

                // Trier les klines du plus récent au plus ancien (comme /api/klines)
                usort($klinesFormatted, static function ($a, $b): int {
                    return $b['openTime'] <=> $a['openTime'];
                });

                $atrResults[$tfValue] = $atr;
                $klinesResults[$tfValue] = $klinesFormatted;
            } catch (\ValueError) {
                $missing[] = $tfValue;
            } catch (\Exception $e) {
                // En cas d'erreur, ajouter au missing
                $missing[] = $tfValue;
            }
        }

        $response = [
            'symbol' => $symbol,
            'atr' => $atrResults,
            'klines' => $klinesResults,
        ];

        if ($missing !== []) {
            $response['missing_timeframes'] = $missing;
        }

        return $this->json($response);
    }
}
