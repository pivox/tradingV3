<?php

declare(strict_types=1);

/**
 * Analyse les blocages MTF (profil scalper) dans les logs mtf-YYYY-MM-DD.log.
 *
 * Usage:
 *   php scripts/analyze_mtf_blockers.php                      # analyse aujourd'hui (UTC)
 *   php scripts/analyze_mtf_blockers.php 2025-11-29           # analyse une date précise (toute la journée)
 *   php scripts/analyze_mtf_blockers.php 2025-11-29 08:05     # analyse à partir de HH:MM (heure UTC, sans secondes)
 *
 * Sorties principales:
 *   - Nombre de "MTF context invalid" par timeframe (1h / 15m) et par invalid_reason.
 *   - Nombre de fois où chaque règle apparaît dans rules_failed (par timeframe).
 *   - Stat sur les logs [MTF_RULE_DEBUG] (pass/fail) par règle et timeframe.
 */

$argDate = $argv[1] ?? null;
$argTime = $argv[2] ?? null;

if ($argDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $argDate)) {
    fwrite(STDERR, "Invalid date format. Expected YYYY-MM-DD.\n");
    exit(1);
}

$date = $argDate ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

$timeFilter = null;
if ($argTime !== null) {
    if (!preg_match('/^\d{2}:\d{2}$/', $argTime)) {
        fwrite(STDERR, "Invalid time format. Expected HH:MM (no seconds).\n");
        exit(1);
    }
    $timeFilter = $argTime;
}

$baseDir = dirname(__DIR__);
$logFile = sprintf('%s/var/log/mtf-%s.log', $baseDir, $date);

if (!is_file($logFile)) {
    fwrite(STDERR, sprintf("Log file not found for date %s: %s\n", $date, $logFile));
    exit(1);
}

$fh = fopen($logFile, 'rb');
if ($fh === false) {
    fwrite(STDERR, sprintf("Unable to open log file: %s\n", $logFile));
    exit(1);
}

$contextStats = [
    // timeframe => ['total' => int, 'by_reason' => [reason => count], 'rules_failed' => [rule => count]]
];

$contextUniqueStats = [
    // timeframe => ['total' => int, 'by_reason' => [reason => count], 'rules_failed' => [rule => count]]
];

$ruleDebugStats = [
    // rule => [timeframe => ['PASS' => int, 'FAIL' => int]]
];

$ruleDebugByTf = [
    // timeframe => [rule => ['PASS' => int, 'FAIL' => int]]
];

$seenContextKeys = [
    // "$tf|$symbol" => true
];

while (($line = fgets($fh)) !== false) {
    if (shouldSkipByTimeFilter($line, $timeFilter)) {
        continue;
    }
    if (str_contains($line, 'msg="[MTF] Context timeframe invalid"')) {
        analyzeContextInvalidLine($line, $contextStats, $contextUniqueStats, $seenContextKeys);
        continue;
    }

    if (str_contains($line, 'msg="[MTF_RULE_DEBUG]"')) {
        analyzeRuleDebugLine($line, $ruleDebugStats, $ruleDebugByTf);
        continue;
    }
}

fclose($fh);

// --- Affichage ---

echo "=== MTF Context Invalid Summary (date={$date}) ===\n\n";
if ($timeFilter !== null) {
    echo "(From time >= {$timeFilter} UTC)\n\n";
}

if ($contextStats === []) {
    echo "No [MTF] Context timeframe invalid entries found.\n\n";
} else {
    foreach ($contextStats as $tf => $stats) {
        $total = $stats['total'] ?? 0;
        echo sprintf("Timeframe: %s\n", $tf);
        echo sprintf("  Total invalid context decisions: %d\n", $total);

        if (!empty($stats['by_reason'])) {
            echo "  By invalid_reason:\n";
            foreach ($stats['by_reason'] as $reason => $count) {
                $pct = $total > 0 ? 100.0 * $count / $total : 0.0;
                echo sprintf("    - %s: %d (%.2f%%)\n", $reason, $count, $pct);
            }
        }

        if (!empty($stats['rules_failed'])) {
            echo "  Top failed rules (rules_failed):\n";
            arsort($stats['rules_failed']);
            $i = 0;
            foreach ($stats['rules_failed'] as $rule => $count) {
                $pct = $total > 0 ? 100.0 * $count / $total : 0.0;
                echo sprintf("    - %s: %d (%.2f%% of invalid)\n", $rule, $count, $pct);
                if (++$i >= 30) {
                    echo "    ... (truncated)\n";
                    break;
                }
            }
        }

        echo "\n";
    }
}

echo "=== MTF Context Invalid Unique Symbols Summary ===\n\n";

if ($contextUniqueStats === []) {
    echo "No unique context invalid entries (by symbol/timeframe).\n\n";
} else {
    foreach ($contextUniqueStats as $tf => $stats) {
        $total = $stats['total'] ?? 0;
        echo sprintf("Timeframe: %s\n", $tf);
        echo sprintf("  Unique symbols with invalid context: %d\n", $total);

        if (!empty($stats['by_reason'])) {
            echo "  By invalid_reason (unique symbols):\n";
            foreach ($stats['by_reason'] as $reason => $count) {
                $pct = $total > 0 ? 100.0 * $count / $total : 0.0;
                echo sprintf("    - %s: %d (%.2f%%)\n", $reason, $count, $pct);
            }
        }

        if (!empty($stats['rules_failed'])) {
            echo "  Top failed rules (unique symbols):\n";
            arsort($stats['rules_failed']);
            $i = 0;
            foreach ($stats['rules_failed'] as $rule => $count) {
                $pct = $total > 0 ? 100.0 * $count / $total : 0.0;
                echo sprintf("    - %s: %d (%.2f%% of unique)\n", $rule, $count, $pct);
                if (++$i >= 30) {
                    echo "    ... (truncated)\n";
                    break;
                }
            }
        }

        echo "\n";
    }
}

echo "=== MTF Rule Debug Summary ([MTF_RULE_DEBUG]) ===\n\n";

if ($ruleDebugStats === []) {
    echo "No [MTF_RULE_DEBUG] entries found.\n";
    exit(0);
}

foreach ($ruleDebugStats as $rule => $perTf) {
    echo sprintf("Rule: %s\n", $rule);
    foreach ($perTf as $tf => $counts) {
        $pass = $counts['PASS'] ?? 0;
        $fail = $counts['FAIL'] ?? 0;
        $total = $pass + $fail;
        $passPct = $total > 0 ? 100.0 * $pass / $total : 0.0;
        $failPct = $total > 0 ? 100.0 * $fail / $total : 0.0;
        echo sprintf(
            "  TF=%-4s PASS=%5d (%.2f%%)  FAIL=%5d (%.2f%%)\n",
            $tf,
            $pass,
            $passPct,
            $fail,
            $failPct
        );
    }
    echo "\n";
}

echo "=== MTF Rule Debug by Timeframe ===\n\n";

if ($ruleDebugByTf === []) {
    echo "No [MTF_RULE_DEBUG] entries found by timeframe.\n";
    exit(0);
}

foreach ($ruleDebugByTf as $tf => $perRule) {
    echo sprintf("Timeframe: %s\n", $tf);
    foreach ($perRule as $rule => $counts) {
        $pass = $counts['PASS'] ?? 0;
        $fail = $counts['FAIL'] ?? 0;
        $total = $pass + $fail;
        $passPct = $total > 0 ? 100.0 * $pass / $total : 0.0;
        $failPct = $total > 0 ? 100.0 * $fail / $total : 0.0;
        echo sprintf(
            "  Rule=%-30s PASS=%5d (%.2f%%)  FAIL=%5d (%.2f%%)\n",
            $rule,
            $pass,
            $passPct,
            $fail,
            $failPct
        );
    }
    echo "\n";
}

/**
 * Analyse une ligne "Context timeframe invalid" et met à jour les stats.
 *
 * Exemple de ligne:
 * [2025-11-29 00:26:05.412] mtf.INFO symbol=BTCUSDT timeframe=1h phase=context mode=pragmatic engine=yaml msg="[MTF] Context timeframe invalid" invalid_reason=NO_LONG_NO_SHORT signal=neutral rules_failed.0=rsi_lt_70 rules_failed.1=price_lte_ma21_plus_k_atr
 *
 * @param string $line
 * @param array<string,array<string,mixed>> $contextStats
 * @param array<string,array<string,mixed>> $contextUniqueStats
 * @param array<string,bool>                $seenContextKeys
 */
function analyzeContextInvalidLine(string $line, array &$contextStats, array &$contextUniqueStats, array &$seenContextKeys): void
{
    // Extraire tous les couples key=value après le msg
    if (!preg_match_all('/\s([a-zA-Z0-9_.]+)=([^ ]+)/', $line, $m)) {
        return;
    }

    $fields = [];
    $rulesFailed = [];

    $keys = $m[1];
    $values = $m[2];

    foreach ($keys as $idx => $key) {
        $value = trim($values[$idx], "\"");
        if (str_starts_with($key, 'rules_failed.')) {
            if ($value !== '') {
                $rulesFailed[] = $value;
            }
            continue;
        }
        $fields[$key] = $value;
    }

    // timeframe: préférer 'timeframe', sinon 'tf'
    $tf = $fields['timeframe'] ?? ($fields['tf'] ?? 'unknown');
    $reason = $fields['invalid_reason'] ?? 'unknown';

    if (!isset($contextStats[$tf])) {
        $contextStats[$tf] = [
            'total' => 0,
            'by_reason' => [],
            'rules_failed' => [],
        ];
    }

    $contextStats[$tf]['total']++;
    if (!isset($contextStats[$tf]['by_reason'][$reason])) {
        $contextStats[$tf]['by_reason'][$reason] = 0;
    }
    $contextStats[$tf]['by_reason'][$reason]++;

    foreach ($rulesFailed as $rule) {
        if (!isset($contextStats[$tf]['rules_failed'][$rule])) {
            $contextStats[$tf]['rules_failed'][$rule] = 0;
        }
        $contextStats[$tf]['rules_failed'][$rule]++;
    }

    // Vue "unique" par symbol/timeframe (on considère chaque symbol+tf au plus une fois)
    $symbol = $fields['symbol'] ?? null;
    if ($symbol !== null) {
        $key = $tf . '|' . $symbol;
        if (!isset($seenContextKeys[$key])) {
            $seenContextKeys[$key] = true;

            if (!isset($contextUniqueStats[$tf])) {
                $contextUniqueStats[$tf] = [
                    'total' => 0,
                    'by_reason' => [],
                    'rules_failed' => [],
                ];
            }

            $contextUniqueStats[$tf]['total']++;
            if (!isset($contextUniqueStats[$tf]['by_reason'][$reason])) {
                $contextUniqueStats[$tf]['by_reason'][$reason] = 0;
            }
            $contextUniqueStats[$tf]['by_reason'][$reason]++;

            foreach ($rulesFailed as $rule) {
                if (!isset($contextUniqueStats[$tf]['rules_failed'][$rule])) {
                    $contextUniqueStats[$tf]['rules_failed'][$rule] = 0;
                }
                $contextUniqueStats[$tf]['rules_failed'][$rule]++;
            }
        }
    }
}

/**
 * Retourne true si la ligne doit être ignorée à cause du filtre horaire (HH:MM).
 *
 * @param string      $line
 * @param string|null $timeFilter
 */
function shouldSkipByTimeFilter(string $line, ?string $timeFilter): bool
{
    if ($timeFilter === null) {
        return false;
    }

    // Format attendu en début de ligne: [YYYY-MM-DD HH:MM:SS.mmm]
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}):\d{2}\.\d+]/', $line, $m)) {
        return false;
    }

    $lineHm = $m[2]; // HH:MM

    // Si la ligne est avant le filtre, on la skip
    return strcmp($lineHm, $timeFilter) < 0;
}

/**
 * Analyse une ligne [MTF_RULE_DEBUG] et met à jour les stats.
 *
 * Exemple de payload:
 * msg="[MTF_RULE_DEBUG]" rule=adx_min_for_trend timeframe=1h result=FAIL value=12.34 threshold=15 meta...
 *
 * @param string $line
 * @param array<string,array<string,array<string,int>>> $ruleDebugStats
 * @param array<string,array<string,array<string,int>>> $ruleDebugByTf
 */
function analyzeRuleDebugLine(string $line, array &$ruleDebugStats, array &$ruleDebugByTf = []): void
{
    if (!preg_match_all('/\s([a-zA-Z0-9_]+)=([^ ]+)/', $line, $m)) {
        return;
    }

    $fields = [];
    $keys = $m[1];
    $values = $m[2];

    foreach ($keys as $idx => $key) {
        $value = trim($values[$idx], "\"");
        $fields[$key] = $value;
    }

    $rule = $fields['rule'] ?? null;
    // timeframe: préférer 'timeframe', sinon 'tf', sinon meta.timeframe
    $tf = $fields['timeframe'] ?? ($fields['tf'] ?? ($fields['meta.timeframe'] ?? 'unknown'));
    $result = strtoupper($fields['result'] ?? '');
    if ($rule === null || ($result !== 'PASS' && $result !== 'FAIL')) {
        return;
    }

    if (!isset($ruleDebugStats[$rule])) {
        $ruleDebugStats[$rule] = [];
    }
    if (!isset($ruleDebugStats[$rule][$tf])) {
        $ruleDebugStats[$rule][$tf] = ['PASS' => 0, 'FAIL' => 0];
    }

    $ruleDebugStats[$rule][$tf][$result]++;

    // Vue complémentaire: regroupement par timeframe puis par règle
    if (!isset($ruleDebugByTf[$tf])) {
        $ruleDebugByTf[$tf] = [];
    }
    if (!isset($ruleDebugByTf[$tf][$rule])) {
        $ruleDebugByTf[$tf][$rule] = ['PASS' => 0, 'FAIL' => 0];
    }
    $ruleDebugByTf[$tf][$rule][$result]++;
}
