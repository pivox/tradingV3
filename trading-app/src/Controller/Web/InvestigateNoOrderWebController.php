<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Process\Process;

final class InvestigateNoOrderWebController extends AbstractController
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/investigate/no-order', name: 'investigate_no_order', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $symbols = trim((string)$request->query->get('symbols', ''));
        $sinceMinutes = max(1, (int)$request->query->get('since_minutes', 10));
        $maxLogFiles = max(1, (int)$request->query->get('max_log_files', 2));
        $autoRefresh = (int)$request->query->get('auto_refresh', 0);

        $results = null;
        $error = null;
        $rows = [];

        if ($symbols !== '') {
            try {
                $cmd = [
                    'php',
                    $this->projectDir . '/bin/console',
                    'investigate:no-order',
                    '--symbols=' . $symbols,
                    '--since-minutes=' . $sinceMinutes,
                    '--max-log-files=' . $maxLogFiles,
                    '--format=json',
                    '--no-ansi',
                    '--no-interaction',
                ];

                $process = new Process($cmd, $this->projectDir, [
                    // Réduire bruit dans la sortie pour fiabiliser le JSON
                    'SHELL_VERBOSITY' => '0',
                ]);
                $process->setTimeout(20);
                $process->mustRun();

                $output = $process->getOutput();
                if ($output === '' && $process->getErrorOutput() !== '') {
                    // Rien sur STDOUT mais STDERR non vide: exposer l'erreur
                    throw new \RuntimeException(trim($process->getErrorOutput()));
                }
                // Tolérer du bruit autour du JSON (ANSI, logs accidentels)
                $jsonPayload = $this->extractJsonObject($output);
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($jsonPayload ?? $output, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Sortie JSON invalide depuis la commande CLI');
                }
                $results = $decoded;

                foreach ($results as $symbol => $r) {
                    $details = is_array($r['details'] ?? null) ? $r['details'] : [];
                    $reason = (string)($r['reason'] ?? ($details['cause'] ?? ''));
                    $zoneDev = isset($details['zone_dev_pct']) && is_numeric($details['zone_dev_pct']) ? (float)$details['zone_dev_pct'] : null;
                    $zoneMax = isset($details['zone_max_dev_pct']) && is_numeric($details['zone_max_dev_pct']) ? (float)$details['zone_max_dev_pct'] : null;

                    $proposal = '';
                    if (($r['status'] ?? '') === 'skipped' && ($reason === 'skipped_out_of_zone' || $reason === 'zone_far_from_market')) {
                        $proposal = $this->buildZoneSkipProposal($zoneDev, $zoneMax);
                    }

                    $rows[] = [
                        'symbol' => (string)$symbol,
                        'status' => (string)($r['status'] ?? 'unknown'),
                        'reason' => $reason,
                        'order_id' => (string)($details['order_id'] ?? ''),
                        'decision_key' => (string)($details['decision_key'] ?? ''),
                        'timeframe' => (string)($details['timeframe'] ?? ''),
                        'kline_time' => (string)($details['kline_time'] ?? ''),
                        'zone_dev_pct' => $zoneDev,
                        'zone_max_dev_pct' => $zoneMax,
                        'proposal' => $proposal,
                    ];
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
            ],
            'error' => $error,
            'results' => $results,
            'rows' => $rows,
        ]);
    }

    /**
     * Extrait le premier objet JSON {} de la sortie si du bruit entoure la charge utile.
     */
    private function extractJsonObject(string $output): ?string
    {
        $start = strpos($output, '{');
        if ($start === false) { return null; }
        $end = strrpos($output, '}');
        if ($end === false || $end <= $start) { return null; }
        $candidate = substr($output, (int)$start, (int)($end - $start + 1));
        return $candidate !== '' ? $candidate : null;
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
