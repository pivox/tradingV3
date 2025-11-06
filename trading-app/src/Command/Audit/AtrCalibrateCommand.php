<?php

declare(strict_types=1);

namespace App\Command\Audit;

use App\Config\MtfValidationConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:atr:calibrate',
    description: 'Calibre les seuils ATR/close par timeframe via percentiles DB et produit un rapport d’impact.'
)]
final class AtrCalibrateCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly MtfValidationConfig $mtfConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, "Fenêtre temporelle (ex: '7 days', '48 hours')", '7 days')
            ->addOption('timeframes', 't', InputOption::VALUE_REQUIRED, "Liste de TF séparée par virgules", '15m,5m,1m,1h,4h')
            ->addOption('low', null, InputOption::VALUE_REQUIRED, 'Quantile bas (ex: 0.10)', '0.10')
            ->addOption('high', null, InputOption::VALUE_REQUIRED, 'Quantile haut (ex: 0.90)', '0.90')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Répertoire de sortie pour les JSON', 'var');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $since = (string) $input->getOption('since');
        $tfs = array_map('trim', explode(',', (string) $input->getOption('timeframes')));
        $qLow = max(0.0, min(1.0, (float) $input->getOption('low')));
        $qHigh = max(0.0, min(1.0, (float) $input->getOption('high')));
        if ($qLow <= 0.0 || $qHigh <= 0.0 || $qLow >= $qHigh) {
            $io->error('Paramètres quantiles invalides. Exemple: --low=0.10 --high=0.90');
            return Command::INVALID;
        }

        $current = (array) ($this->mtfConfig->getDefault('atr_pct_thresholds', []));
        $io->title('Calibration ATR/close par timeframe');
        $io->writeln(sprintf('Fenêtre: %s | Quantiles: [%.2f, %.2f]', $since, $qLow, $qHigh));
        $outDir = (string) $input->getOption('output-dir');
        $this->ensureOutputDir($outDir, $io);

        $summary = [
            'since' => $since,
            'quantiles' => ['low' => $qLow, 'median' => 0.5, 'high' => $qHigh],
            'timeframes' => [],
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];

        foreach ($tfs as $tf) {
            if ($tf === '') continue;
            $io->section(sprintf('Timeframe %s', $tf));
            try {
                $row = $this->computePercentiles($tf, $since, [$qLow, 0.5, $qHigh]);
            } catch (\Throwable $e) {
                $io->warning(sprintf('Échec calcul percentiles pour %s: %s', $tf, $e->getMessage()));
                $this->writeTfJson($outDir, $tf, [
                    'tf' => $tf,
                    'since' => $since,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $total = (int) ($row['total'] ?? 0);
            if ($total === 0) {
                $io->warning('Aucune donnée exploitable (indicator_snapshots manquants pour ce TF).');
                $this->writeTfJson($outDir, $tf, [
                    'tf' => $tf,
                    'since' => $since,
                    'total' => 0,
                    'message' => 'no_data',
                ]);
                continue;
            }

            $p = $row['p'] ?? [];
            if (!\is_array($p) || count($p) < 3) {
                $io->warning('Percentiles indisponibles ou incomplets.');
                continue;
            }

            [$pLow, $p50, $pHigh] = $p;
            $recommendMin = (float) $pLow;
            $recommendMax = (float) $pHigh;

            $curMin = isset($current[$tf]['min']) ? (float) $current[$tf]['min'] : null;
            $curMax = isset($current[$tf]['max']) ? (float) $current[$tf]['max'] : null;

            $impactCurrent = $this->impactFor($tf, $since, $curMin, $curMax);
            $impactRecom   = $this->impactFor($tf, $since, $recommendMin, $recommendMax);

            $io->writeln(sprintf('- échantillons: %d  | p50=%.4f', $total, $p50));
            if ($curMin !== null && $curMax !== null) {
            $io->writeln(sprintf('- seuils actuels: [%.4f, %.4f]  | hors-plage: %d (%.1f%%)', $curMin, $curMax, $impactCurrent['out'], $impactCurrent['out_pct']));
            } else {
                $io->writeln('- seuils actuels: non définis');
            }
            $io->writeln(sprintf('- seuils recommandés: [%.4f, %.4f]  | hors-plage: %d (%.1f%%)', $recommendMin, $recommendMax, $impactRecom['out'], $impactRecom['out_pct']));

            $io->writeln('Patch YAML suggéré:');
            $io->block(sprintf("defaults:\n    atr_pct_thresholds:\n        '%s': { min: %.4f, max: %.4f }", $tf, $recommendMin, $recommendMax), style: 'info');

            // Écriture JSON par timeframe
            $payload = [
                'tf' => $tf,
                'since' => $since,
                'total' => $total,
                'percentiles' => [
                    'low' => $pLow,
                    'median' => $p50,
                    'high' => $pHigh,
                ],
                'current_thresholds' => [
                    'min' => $curMin,
                    'max' => $curMax,
                ],
                'recommended' => [
                    'min' => $recommendMin,
                    'max' => $recommendMax,
                ],
                'impact_current' => $impactCurrent,
                'impact_recommended' => $impactRecom,
                'patch_yaml' => sprintf("defaults:\n    atr_pct_thresholds:\n        '%s': { min: %.4f, max: %.4f }", $tf, $recommendMin, $recommendMax),
            ];
            $this->writeTfJson($outDir, $tf, $payload);
            $summary['timeframes'][$tf] = $payload;
        }

        // Écriture d'un résumé global
        $this->writeJson($outDir . '/atr_calibration_summary.json', $summary);

        $io->success(sprintf('Calibration et rapport d’impact terminés. Fichiers JSON écrits dans %s', $outDir));
        return Command::SUCCESS;
    }

    /**
     * @param float[] $quantiles
     * @return array{p: float[], total: int}
     */
    private function computePercentiles(string $tf, string $since, array $quantiles): array
    {
        // Support 3 quantiles (low, median, high); inline constants in SQL to avoid positional param quirks
        $q1 = (float) ($quantiles[0] ?? 0.10);
        $q2 = (float) ($quantiles[1] ?? 0.50);
        $q3 = (float) ($quantiles[2] ?? 0.90);
        $q1s = sprintf('%.6f', $q1);
        $q2s = sprintf('%.6f', $q2);
        $q3s = sprintf('%.6f', $q3);

        $sql = <<<SQL
WITH base AS (
  SELECT ((values->>'atr')::numeric / NULLIF((values->>'close')::numeric,0)) AS r
  FROM indicator_snapshots
  WHERE timeframe = :tf
    AND inserted_at > NOW() - (:since)::interval
    AND (values ? 'atr') AND (values ? 'close')
    AND (values->>'close')::numeric > 0
)
SELECT
  percentile_cont($q1s) WITHIN GROUP (ORDER BY r) AS p1,
  percentile_cont($q2s) WITHIN GROUP (ORDER BY r) AS p2,
  percentile_cont($q3s) WITHIN GROUP (ORDER BY r) AS p3,
  (SELECT COUNT(*) FROM base) AS total
FROM base
SQL;

        $row = (array) $this->conn->fetchAssociative($sql, [
            'tf' => $tf,
            'since' => $since,
        ]);

        return [
            'p' => [
                isset($row['p1']) ? (float) $row['p1'] : null,
                isset($row['p2']) ? (float) $row['p2'] : null,
                isset($row['p3']) ? (float) $row['p3'] : null,
            ],
            'total' => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * @return array{out: int, out_pct: float}
     */
    private function impactFor(string $tf, string $since, ?float $min, ?float $max): array
    {
        if ($min === null || $max === null || $min <= 0.0 || $max <= 0.0 || $min >= $max) {
            return ['out' => 0, 'out_pct' => 0.0];
        }

        $sql = <<<SQL
WITH base AS (
  SELECT ((values->>'atr')::numeric / NULLIF((values->>'close')::numeric,0)) AS r
  FROM indicator_snapshots
  WHERE timeframe = :tf
    AND inserted_at > NOW() - (:since)::interval
    AND (values ? 'atr') AND (values ? 'close')
    AND (values->>'close')::numeric > 0
)
SELECT COUNT(*) AS total,
       COUNT(*) FILTER (WHERE r < :min OR r > :max) AS out
FROM base
SQL;

        $row = (array) $this->conn->fetchAssociative($sql, [
            'tf' => $tf,
            'since' => $since,
            'min' => $min,
            'max' => $max,
        ]);
        $total = (int) ($row['total'] ?? 0);
        $out = (int) ($row['out'] ?? 0);
        $pct = $total > 0 ? (100.0 * $out / $total) : 0.0;
        return ['out' => $out, 'out_pct' => round($pct, 1)];
    }

    private function ensureOutputDir(string $dir, SymfonyStyle $io): void
    {
        if ($dir === '' || $dir === '.' || $dir === '/') {
            return;
        }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $io->warning(sprintf('Impossible de créer le répertoire de sortie: %s', $dir));
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeTfJson(string $dir, string $tf, array $data): void
    {
        $path = rtrim($dir, '/').'/atr_calibration_'.$tf.'.json';
        $this->writeJson($path, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        try {
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // best effort; keep console output as fallback
        }
    }
}
