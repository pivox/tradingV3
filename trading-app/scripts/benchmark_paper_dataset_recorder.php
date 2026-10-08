<?php

declare(strict_types=1);

use App\Trading\Paper\Capture\PaperPublicLiveManifestFactory;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;

require dirname(__DIR__) . '/vendor/autoload.php';

$eventsTotal = filter_var($argv[1] ?? '150000', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2_000_000],
]);
$trancheSize = filter_var($argv[2] ?? '25000', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 256, 'max_range' => 250_000],
]);
if (!\is_int($eventsTotal) || !\is_int($trancheSize)) {
    fwrite(STDERR, "usage: benchmark_paper_dataset_recorder.php [events] [tranche]\n");
    exit(2);
}

$root = sys_get_temp_dir() . '/paper-recorder-benchmark-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true)) {
    throw new RuntimeException('paper_recorder_benchmark_root_failed');
}
$resolved = realpath($root);
if (!\is_string($resolved)) {
    throw new RuntimeException('paper_recorder_benchmark_root_failed');
}
$root = $resolved;
$datasetId = 'benchmark-okx-' . bin2hex(random_bytes(6)) . '-mainnet';
$manifest = (new PaperPublicLiveManifestFactory())->create(
    PaperMarketDataVenue::OKX,
    $datasetId,
);
$recorder = new PaperDatasetRecorder(
    $root,
    $manifest,
    recordingManifestCheckpointInterval: 10_000,
);
$eventForOrdinal = static function (int $ordinal): PaperMarketEvent {
    $seconds = intdiv($ordinal - 1, 1_000_000);
    $microseconds = ($ordinal - 1) % 1_000_000;
    $exchange = new DateTimeImmutable(sprintf(
        '2026-01-01T00:00:%02d.%06dZ',
        $seconds % 60,
        $microseconds,
    ));

    return PaperMarketEvent::create(
        network: App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
        venue: PaperMarketDataVenue::OKX,
        symbol: 'BTCUSDT',
        channel: PaperMarketDataChannel::PUBLIC_TRADE,
        exchangeTimestamp: $exchange,
        receivedTimestamp: $exchange->modify('+1 millisecond'),
        sequence: (string) $ordinal,
        payload: ['price' => '30000.0', 'size' => '0.001'],
    );
};

$started = hrtime(true);
$trancheStarted = $started;
$lastReported = 0;
$results = [];
for ($first = 1; $first <= $eventsTotal; $first += 256) {
    $batch = [];
    $last = min($eventsTotal, $first + 255);
    for ($ordinal = $first; $ordinal <= $last; ++$ordinal) {
        $batch[] = $eventForOrdinal($ordinal);
    }
    $recorder->appendBatch($batch);

    if ($last - $lastReported >= $trancheSize || $last === $eventsTotal) {
        $now = hrtime(true);
        $elapsed = ($now - $trancheStarted) / 1_000_000_000;
        $eventsInTranche = $last - $lastReported;
        $results[] = [
            'events_durable_total' => $last,
            'tranche_seconds' => round($elapsed, 6),
            'tranche_events_per_second' => round($eventsInTranche / $elapsed, 2),
            'php_memory_bytes' => memory_get_usage(true),
            'php_peak_memory_bytes' => memory_get_peak_usage(true),
            'events_file_bytes' => filesize($root . '/' . $datasetId . '/events.ndjson'),
        ];
        $trancheStarted = $now;
        $lastReported = $last;
    }
}
$recorder = null;
gc_collect_cycles();
$restartStarted = hrtime(true);
$recorder = new PaperDatasetRecorder(
    $root,
    $manifest,
    recordingManifestCheckpointInterval: 10_000,
);
$restartSeconds = (hrtime(true) - $restartStarted) / 1_000_000_000;
$replayResult = $recorder->append($eventForOrdinal(1));
$terminal = $recorder->complete();
$duration = (hrtime(true) - $started) / 1_000_000_000;

echo CanonicalJson::encode([
    'schema_version' => 'paper-recorder-benchmark-v1',
    'dataset_directory' => $root . '/' . $datasetId,
    'events_total' => $eventsTotal,
    'duration_seconds' => round($duration, 6),
    'events_per_second' => round($eventsTotal / $duration, 2),
    'memory_limit' => ini_get('memory_limit'),
    'php_peak_memory_bytes' => memory_get_peak_usage(true),
    'restart_seconds' => round($restartSeconds, 6),
    'oldest_event_replay_result' => $replayResult->value,
    'terminal_state' => $terminal->state->value,
    'terminal_sha256' => $terminal->eventsFileSha256,
    'tranches' => $results,
]) . "\n";
