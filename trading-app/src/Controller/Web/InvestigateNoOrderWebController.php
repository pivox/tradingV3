<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Service\NoOrderInvestigationResult;
use App\Service\NoOrderInvestigationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class InvestigateNoOrderWebController extends AbstractController
{
    public function __construct(
        private readonly NoOrderInvestigationService $investigationService,
    ) {
    }

    #[Route('/investigate/no-order', name: 'investigate_no_order', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $symbols = trim((string)$request->query->get('symbols', ''));
        $sinceMinutes = max(1, (int)$request->query->get('since_minutes', 10));
        $maxLogFiles = max(1, (int)$request->query->get('max_log_files', 2));
        $autoRefresh = (int)$request->query->get('auto_refresh', 0);
        $timeout = min(120, max(5, (int)$request->query->get('timeout', 30)));

        $results = null;
        $error = null;
        $rows = [];
        $symbolsList = array_values(array_filter(array_map(static fn(string $s) => strtoupper(trim($s)), explode(',', $symbols))));

        if ($symbols !== '' && empty($symbolsList)) {
            $error = 'Liste de symboles vide après parsing.';
        }

        if ($symbols !== '' && $symbolsList !== []) {
            try {
                $since = (new \DateTimeImmutable(sprintf('-%d minutes', max(1, $sinceMinutes))))
                    ->setTimezone(new \DateTimeZone('UTC'));
                $investigationResults = $this->investigationService->investigate($symbolsList, $since, $maxLogFiles);
                $results = array_map(static fn(NoOrderInvestigationResult $r) => $r->toArray(), $investigationResults);

                foreach ($investigationResults as $symbol => $result) {
                    $details = $result->details;
                    $reason = (string)($result->reason ?? ($details['cause'] ?? ''));
                    $zoneDev = isset($details['zone_dev_pct']) && is_numeric($details['zone_dev_pct']) ? (float)$details['zone_dev_pct'] : null;
                    $zoneMax = isset($details['zone_max_dev_pct']) && is_numeric($details['zone_max_dev_pct']) ? (float)$details['zone_max_dev_pct'] : null;
                    $priceVsMa21 = isset($details['price_vs_ma21_k_atr']) && is_numeric($details['price_vs_ma21_k_atr']) ? (float)$details['price_vs_ma21_k_atr'] : null;
                    $entryRsi = isset($details['entry_rsi']) && is_numeric($details['entry_rsi']) ? (float)$details['entry_rsi'] : null;
                    $volumeRatio = isset($details['volume_ratio']) && is_numeric($details['volume_ratio']) ? (float)$details['volume_ratio'] : null;
                    $rMultipleFinal = null;
                    if (isset($details['r_multiple_final']) && is_numeric($details['r_multiple_final'])) {
                        $rMultipleFinal = (float) $details['r_multiple_final'];
                    } elseif (isset($details['expected_r_multiple']) && is_numeric($details['expected_r_multiple'])) {
                        $rMultipleFinal = (float) $details['expected_r_multiple'];
                    }
                    $proposal = '';
                    if ($result->status === 'skipped' && in_array($reason, ['skipped_out_of_zone', 'zone_far_from_market'], true)) {
                        $proposal = $this->buildZoneSkipProposal($zoneDev, $zoneMax);
                    }

                    $rows[] = [
                        'symbol' => $symbol,
                        'status' => $result->status,
                        'reason' => $reason,
                        'order_id' => (string)($details['order_id'] ?? ''),
                        'decision_key' => (string)($details['decision_key'] ?? ''),
                        'timeframe' => (string)($details['timeframe'] ?? ''),
                        'kline_time' => (string)($details['kline_time'] ?? ''),
                        'zone_dev_pct' => $zoneDev,
                        'zone_max_dev_pct' => $zoneMax,
                        'price_vs_ma21_k_atr' => $priceVsMa21,
                        'entry_rsi' => $entryRsi,
                        'volume_ratio' => $volumeRatio,
                        'r_multiple_final' => $rMultipleFinal,
                        'proposal' => $proposal,
                    ];
                }

                if (empty($rows)) {
                    foreach ($symbolsList as $sym) {
                        if ($sym === '') { continue; }
                        $rows[] = [
                            'symbol' => $sym,
                            'status' => 'unknown',
                            'reason' => '',
                            'order_id' => '',
                            'decision_key' => '',
                            'timeframe' => '',
                            'kline_time' => '',
                            'zone_dev_pct' => null,
                            'zone_max_dev_pct' => null,
                            'price_vs_ma21_k_atr' => null,
                            'entry_rsi' => null,
                            'volume_ratio' => null,
                            'r_multiple_final' => null,
                            'proposal' => '',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('Investigate/no_order.html.twig', [
            'query' => [
                'symbols' => $symbols,
                'since_minutes' => $sinceMinutes,
                'max_log_files' => $maxLogFiles,
                'auto_refresh' => $autoRefresh,
                'timeout' => $timeout,
            ],
            'error' => $error,
            'debug' => [
                'enabled' => (bool)$request->query->get('debug', false),
                'symbols' => $symbolsList,
            ],
            'results' => $results,
            'rows' => $rows,
        ]);
    }
    
    private function buildZoneSkipProposal(?float $zoneDev, ?float $zoneMax): string
    {
        if ($zoneDev === null || $zoneMax === null || $zoneDev <= 0.0 || $zoneMax <= 0.0) {
            return 'Check logs: zone_dev_pct/max manquants';
        }

        $ratio = $zoneDev / max(1e-9, $zoneMax);
        if ($ratio <= 1.05) {
            $needed = max($zoneDev, $zoneMax * 1.02);
            return sprintf(
                'Près du seuil: relever zone_max_deviation_pct à ~%.2f%% ou permettre une entrée marché avec slippage cap; envisager fallback 5m.',
                $needed * 100.0
            );
        }
        if ($ratio <= 1.5) {
            $needed = $zoneDev;
            return sprintf(
                'Écart modéré: tester un fallback exécution 5m; temporairement mettre zone_max_deviation_pct à ~%.2f%% si acceptable.',
                $needed * 100.0
            );
        }
        return 'Écart important: éviter de relâcher; attendre ou revoir la largeur de zone.';
    }
}
