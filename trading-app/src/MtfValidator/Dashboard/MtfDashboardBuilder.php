<?php

declare(strict_types=1);

namespace App\MtfValidator\Dashboard;

use App\Entity\MtfState;
use App\Logging\TraceIdProvider;
use App\Repository\MtfStateRepository;
use App\Repository\TradeLifecycleEventRepository;
use App\Repository\TradeZoneEventRepository; // ou équivalent pour les raisons "pas de trade"

final class MtfDashboardBuilder
{
    public function __construct(
        private readonly MtfStateRepository $stateRepository,
        private readonly TradeLifecycleEventRepository $tradeLifecycleRepo,
        private readonly TradeZoneEventRepository $zoneEventRepo,
        private readonly TraceIdProvider $traceIdProvider,
    ) {}

    /**
     * @return array{
     *   with_trades: array<int, array<string,mixed>>,
     *   ready_no_trade: array<int, array<string,mixed>>,
     *   blocked_by_tf: array<string, array<int, array<string,mixed>>>
     * }
     */
    public function build(string $startFromTimeframe): array
    {
        $startFrom = strtolower($startFromTimeframe); // '4h' ou '1h'

        // 1) Récupérer tous les states pertinents (tu peux réutiliser getSummary/startFrom)
        /** @var MtfState[] $states */
        $states = $this->stateRepository->getStatesForDashboard($startFrom);

        // 2) Préparer les conteneurs
        $withTrades = [];
        $readyNoTrade = [];
        $blockedByTf = [
            '4h' => [],
            '1h' => [],
            '15m' => [],
            '5m' => [],
            '1m' => [],
        ];

        // 3) Pré-classification brute par symbole
        $symbols = array_map(static fn(MtfState $s) => $s->getSymbol(), $states);

        // 3a) Infos trades par symbole (position / ordre ouvert, etc.)
        $tradesBySymbol = $this->tradeLifecycleRepo->getActiveOrRecentBySymbols($symbols);
        // Format attendu (à adapter à ton repo) :
        // [
        //   'BTCUSDT' => ['has_trade' => true, 'side' => 'LONG', 'position_status' => 'OPEN', ...],
        //   ...
        // ]

        // 3b) Dernière raison "pas de trade" par symbole (zone skipped / invalid / etc.)
        $noTradeReasonBySymbol = $this->zoneEventRepo->getLastReasonBySymbols($symbols);
        // Format :
        // [
        //   'BTCUSDT' => ['reason' => 'skipped_out_of_zone', 'happened_at' => DateTimeImmutable, ...],
        // ]

        foreach ($states as $state) {
            $symbol = $state->getSymbol();

            // 4) Déterminer la progression dans la cascade TF
            $progress = $this->computeProgress($state, $startFrom);

            // "ready" = cascade complète jusqu’au dernier TF (1m)
            $isReady = $progress['last_tf'] === '1m' && $progress['missing_tf'] === null;

            $tradeInfo = $tradesBySymbol[$symbol] ?? ['has_trade' => false];
            $hasTrade = (bool)($tradeInfo['has_trade'] ?? false);

            if ($hasTrade) {
                // Section 1 : symboles avec trade placé
                // Récupérer ou générer le trace_id pour ce symbole
                $traceId = $this->traceIdProvider->getOrCreate($symbol);
                
                $withTrades[] = [
                    'id' => $state->getId(),
                    'symbol' => $symbol,
                    'side' => $tradeInfo['side'] ?? null,
                    'status' => $tradeInfo['position_status'] ?? null,
                    'trace_id' => $traceId,
                    'last_event' => $tradeInfo['last_event'] ?? null,
                    'last_event_at' => $tradeInfo['last_event_at'] instanceof \DateTimeInterface
                        ? $tradeInfo['last_event_at']->format(\DateTimeInterface::ATOM)
                        : null,
                    'last_tf' => $progress['last_tf'],
                ];
                continue;
            }

            if ($isReady) {
                // Section 2 : ready mais pas de trade + raison
                $reasonInfo = $noTradeReasonBySymbol[$symbol] ?? null;

                $readyNoTrade[] = [
                    'id' => $state->getId(),
                    'symbol' => $symbol,
                    'side' => $state->get1mSide() ?? $state->get5mSide() ?? null, // selon ce que tu stockes
                    'last_tf' => $progress['last_tf'], // normalement '1m'
                    'reason' => $reasonInfo['reason'] ?? null,
                    'reason_at' => isset($reasonInfo['happened_at']) && $reasonInfo['happened_at'] instanceof \DateTimeInterface
                        ? $reasonInfo['happened_at']->format(\DateTimeInterface::ATOM)
                        : null,
                ];
                continue;
            }

            // Section 3 : bloqué à un TF (dernier TF atteint)
            $lastTf = $progress['last_tf'] ?? $startFrom;
            if (!isset($blockedByTf[$lastTf])) {
                $blockedByTf[$lastTf] = [];
            }

            $formatDateTime = static function (?\DateTimeInterface $dt): ?string {
                return $dt instanceof \DateTimeInterface
                    ? $dt->format(\DateTimeInterface::ATOM)
                    : null;
            };

            $blockedByTf[$lastTf][] = [
                'id' => $state->getId(),
                'symbol' => $symbol,
                'last_tf' => $lastTf,
                'missing_tf' => $progress['missing_tf'], // prochain TF manquant
                'k4h_time' => $formatDateTime($state->getK4hTime()),
                'k1h_time' => $formatDateTime($state->getK1hTime()),
                'k15m_time' => $formatDateTime($state->getK15mTime()),
                'k5m_time' => $formatDateTime($state->getK5mTime()),
                'k1m_time' => $formatDateTime($state->getK1mTime()),
            ];
        }

        // Option : trier chaque groupe par symbole
        foreach ($blockedByTf as &$group) {
            usort($group, static fn($a, $b) => strcmp($a['symbol'], $b['symbol']));
        }
        unset($group);

        usort($withTrades, static fn($a, $b) => strcmp($a['symbol'], $b['symbol']));
        usort($readyNoTrade, static fn($a, $b) => strcmp($a['symbol'], $b['symbol']));

        return [
            'with_trades' => $withTrades,
            'ready_no_trade' => $readyNoTrade,
            'blocked_by_tf' => $blockedByTf,
        ];
    }

    /**
     * Calcule jusqu’où le state est allé :
     * - last_tf: 4h / 1h / 15m / 5m / 1m
     * - missing_tf: le premier TF manquant dans la chaîne (ou null si complet)
     *
     * @return array{last_tf: ?string, missing_tf: ?string}
     */
    private function computeProgress(MtfState $state, string $startFrom): array
    {
        // Chaîne logique selon le start_from_timeframe
        $chain = $startFrom === '1h'
            ? [
                'k1hTime' => '1h',
                'k15mTime' => '15m',
                'k5mTime' => '5m',
                'k1mTime' => '1m',
            ]
            : [
                'k4hTime' => '4h',
                'k1hTime' => '1h',
                'k15mTime' => '15m',
                'k5mTime' => '5m',
                'k1mTime' => '1m',
            ];

        $lastTf = null;
        $missingTf = null;

        foreach ($chain as $property => $label) {
            $getter = 'get' . ucfirst($property); // getK4hTime, ...
            if (!method_exists($state, $getter)) {
                continue;
            }

            $value = $state->{$getter}();
            if ($value instanceof \DateTimeInterface) {
                $lastTf = $label;
                continue;
            }

            $missingTf = $label;
            break;
        }

        return [
            'last_tf' => $lastTf,
            'missing_tf' => $missingTf,
        ];
    }
}
