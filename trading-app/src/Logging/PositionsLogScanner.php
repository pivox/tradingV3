<?php

declare(strict_types=1);

namespace App\Logging;

use App\Logging\Dto\PositionsLogScanResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PositionsLogScanner
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<string>
     */
    public function findRecentPositionLogs(int $maxFiles): array
    {
        $logDir = rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            return [];
        }

        $files = array_values(array_filter(scandir($logDir) ?: [], static fn(string $file) => str_starts_with($file, 'positions-') && str_ends_with($file, '.log')));
        usort($files, static fn(string $a, string $b): int => strcmp($b, $a));

        $files = array_slice($files, 0, max(1, $maxFiles));

        return array_map(static fn(string $file) => $logDir . DIRECTORY_SEPARATOR . $file, $files);
    }

    /**
     * @param list<string> $logFiles
     */
    public function scanSymbol(string $symbol, array $logFiles, \DateTimeImmutable $since): PositionsLogScanResult
    {
        $status = null;
        $reason = null;
        $details = [];

        $symbolNeedle = 'symbol=' . strtoupper($symbol);
        $payloadNeedle = 'payload.symbol=' . strtoupper($symbol);

        foreach ($logFiles as $file) {
            try {
                $fh = new \SplFileObject($file, 'r');
                while (!$fh->eof()) {
                    $line = (string) $fh->fgets();
                    if ($line === '') {
                        continue;
                    }
                    if (!str_contains($line, $symbolNeedle) && !str_contains($line, $payloadNeedle)) {
                        continue;
                    }

                    $ts = $this->extractTimestamp($line);
                    if ($ts !== null && $ts < $since) {
                        continue;
                    }

                    if (str_contains($line, 'positions.order_submit.success')) {
                        $status = 'submitted';
                        $details['order_id'] = $this->extractToken($line, 'order_id');
                        $details['client_order_id'] = $this->extractToken($line, 'client_order_id');
                        $details['decision_key'] = $this->extractToken($line, 'decision_key');

                        return new PositionsLogScanResult($status, null, $details);
                    }

                    if (str_contains($line, 'order_journey.trade_entry.skipped')) {
                        $status = 'skipped';
                        $reason = $this->extractToken($line, 'reason') ?? 'skipped';
                        $details['decision_key'] = $this->extractToken($line, 'decision_key');
                        foreach (['candidate', 'zone_min', 'zone_max', 'zone_dev_pct', 'zone_max_dev_pct', 'price_vs_ma21_k_atr', 'entry_rsi', 'volume_ratio', 'r_multiple_final'] as $key) {
                            $value = $this->extractContextToken($line, $key);
                            if ($value !== null) {
                                $details[$key] = is_numeric($value) ? (float) $value : $value;
                            }
                        }
                    }

                    if (str_contains($line, 'build_order_plan.zone_skipped_for_execution')) {
                        $status = 'skipped';
                        $reason = 'zone_far_from_market';
                        foreach (['candidate', 'zone_min', 'zone_max', 'zone_dev_pct', 'zone_max_dev_pct', 'price_vs_ma21_k_atr', 'entry_rsi', 'volume_ratio', 'r_multiple_final'] as $key) {
                            $value = $this->extractToken($line, $key);
                            if ($value !== null) {
                                $details[$key] = is_numeric($value) ? (float) $value : $value;
                            }
                        }
                    }

                    if ($status === null && (str_contains($line, 'positions.order_submit.fail') || str_contains($line, 'positions.order_submit.error'))) {
                        $status = 'error';
                        $reason = $this->extractToken($line, 'reason') ?? 'submit_failed';
                        $details['client_order_id'] = $this->extractToken($line, 'client_order_id');
                        $details['decision_key'] = $this->extractToken($line, 'decision_key');
                    }
                }
            } catch (\Throwable) {
                // Intentionally swallow file errors to keep investigation resilient
            }
        }

        return new PositionsLogScanResult($status, $reason, $details);
    }

    private function extractTimestamp(string $line): ?\DateTimeImmutable
    {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/u', $line, $matches) === 1) {
            try {
                return new \DateTimeImmutable($matches[1] . ' ' . $matches[2], new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function extractToken(string $line, string $key): ?string
    {
        $pattern = sprintf('/%s=([^\s\"]+|\"([^\"]*)\")/u', preg_quote($key, '/'));
        if (preg_match($pattern, $line, $matches) === 1) {
            $value = $matches[1];
            if (strlen($value) > 1 && $value[0] === '"' && str_ends_with($value, '"')) {
                return substr($value, 1, -1);
            }

            return $value;
        }

        return null;
    }

    private function extractContextToken(string $line, string $key): ?string
    {
        $pattern = sprintf('/context\\.%s=([^\s\"]+|\"([^\"]*)\")/u', preg_quote($key, '/'));
        if (preg_match($pattern, $line, $matches) === 1) {
            $value = $matches[1];
            if (strlen($value) > 1 && $value[0] === '"' && str_ends_with($value, '"')) {
                return substr($value, 1, -1);
            }

            return $value;
        }

        return null;
    }
}
