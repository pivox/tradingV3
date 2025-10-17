<?php
declare(strict_types=1);

namespace App\Worker;

use React\EventLoop\Loop;
use App\Infra\BitmartWsClient;
use PDO;

final class KlineWorker
{
    /** @var array<string, bool> channel => active? */
    private array $active = [];
    /** @var array<string> channels en attente */
    private array $pendingSubscriptions = [];
    /** @var array<string> channels en attente */
    private array $pendingUnsubscriptions = [];
    /** @var array<string, array<string, int>> [$symbol][$tf] = ts_ms */
    private array $lastOpen = [];

    /** Mapping TF interne -> BitMart */
    private array $timeframeMapping = [
        '1m' => '1m',
        '5m' => '5m',
        '15m'=> '15m',
        '30m'=> '30m',
        '1h' => '1H',
        '2h' => '2H',
        '4h' => '4H',
        '1d' => '1D',
        '1w' => '1W',
    ];

    private ?PDO $pdo = null;

    public function __construct(
        private BitmartWsClient $ws,
        private int $subscribeBatch,
        private int $subscribeDelayMs,
        private int $pingIntervalS,
    ) {
        $this->initDatabase();
    }

    /* ===========================
       API publique
       =========================== */

    public function subscribe(string $symbol, array $tfs): void
    {
        foreach ($tfs as $tf) {
            $bitmartTf = $this->timeframeMapping[$tf] ?? $tf;
            $ch = "futures/klineBin{$bitmartTf}:{$symbol}";
            if (!isset($this->active[$ch])) {
                $this->active[$ch] = true;
                $this->pendingSubscriptions[] = $ch;
                fwrite(STDOUT, "[KLINE] Queued subscription: {$ch}\n");
            }
        }
    }

    public function unsubscribe(string $symbol, array $tfs): void
    {
        foreach ($tfs as $tf) {
            $bitmartTf = $this->timeframeMapping[$tf] ?? $tf;
            $ch = "futures/klineBin{$bitmartTf}:{$symbol}";
            if (isset($this->active[$ch])) {
                unset($this->active[$ch]);
                $this->pendingUnsubscriptions[] = $ch;
                fwrite(STDOUT, "[KLINE] Queued unsubscription: {$ch}\n");
            }
        }
    }

    public function run(): void
    {
        // Batch de (dés)abonnements
        Loop::addPeriodicTimer($this->subscribeDelayMs / 1000, function () {
            $this->processPendingSubscriptions();
            $this->processPendingUnsubscriptions();
        });

        // Ping régulier
        Loop::addPeriodicTimer($this->pingIntervalS, function () {
            if ($this->ws->isConnected()) {
                $this->ws->ping();
                fwrite(STDOUT, "[PING] sent\n");
            }
        });

        // Handlers WS
        $this->ws->onOpen(function () {
            $this->handleConnectionOpened();
        });

        $this->ws->onClose(function () {
            $this->handleConnectionLost();
        });

        $this->ws->onMessage(function (string $raw) {
            // === LOG BRUT DU MESSAGE WS ===
            error_log("[WS_RAW] ".$raw);
            $this->handleMessage($raw);
        });
    }

    public function getSubscribedChannels(): array
    {
        return array_keys($this->active);
    }

    public function isSubscribed(string $symbol, string $tf): bool
    {
        $bitmartTf = $this->timeframeMapping[$tf] ?? $tf;
        $ch = "futures/klineBin{$bitmartTf}:{$symbol}";
        return isset($this->active[$ch]);
    }

    /* ===========================
       (Un)subscribe batching
       =========================== */

    private function processPendingSubscriptions(): void
    {
        if (empty($this->pendingSubscriptions) || !$this->ws->isConnected()) {
            return;
        }
        $batch = array_splice($this->pendingSubscriptions, 0, $this->subscribeBatch);
        if (!empty($batch)) {
            $this->ws->subscribe($batch);
            // === LOG BRUT DES REQUÊTES SUB ===
            error_log("[SUB_REQ] " . json_encode(['action' => 'subscribe', 'args' => $batch]));
            fwrite(STDOUT, "[KLINE] Subscribed to: " . implode(', ', $batch) . "\n");
        }
    }

    private function processPendingUnsubscriptions(): void
    {
        if (empty($this->pendingUnsubscriptions) || !$this->ws->isConnected()) {
            return;
        }
        $batch = array_splice($this->pendingUnsubscriptions, 0, $this->subscribeBatch);
        if (!empty($batch)) {
            $this->ws->unsubscribe($batch);
            // === LOG BRUT DES REQUÊTES UNSUB ===
            error_log("[UNSUB_REQ] " . json_encode(['action' => 'unsubscribe', 'args' => $batch]));
            fwrite(STDOUT, "[KLINE] Unsubscribed from: " . implode(', ', $batch) . "\n");
        }
    }

    /* ===========================
       WS handlers
       =========================== */

    private function handleConnectionOpened(): void
    {
        fwrite(STDOUT, "[KLINE] WebSocket connection opened\n");
        // Les souscriptions en attente seront traitées par le timer.
    }

    private function handleConnectionLost(): void
    {
        fwrite(STDOUT, "[KLINE] WebSocket connection lost, requeue subscriptions\n");
        // Snapshot AVANT reset
        $channels = array_keys($this->active);
        $this->active = [];
        // Requeue pour ré-abonnement à la reconnexion
        foreach ($channels as $ch) {
            $this->pendingSubscriptions[] = $ch;
        }
    }

    private function handleMessage(string $raw): void
    {
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            error_log("[KLINE_DEBUG] Non-JSON or invalid JSON payload");
            return;
        }

        // Filtrer les messages klineBin avec data
        $group = $d['group'] ?? '';
        $data = $d['data'] ?? null;
        
        if ($group && str_starts_with($group, 'futures/klineBin') && $data !== null) {
            $this->processKlineData($d, $raw);
        } elseif ($group && str_starts_with($group, 'futures/klineBin') && isset($d['action'])) {
            // Message de confirmation d'abonnement/désabonnement - on l'ignore
            fwrite(STDOUT, "[KLINE] Confirmation: {$d['action']} for {$group}\n");
        }
    }

    /* ===========================
       Parsing & Persistence
       =========================== */

    private function processKlineData(array $data, string $raw): void
    {
        $group = (string)($data['group'] ?? '');
        $payload = $data['data'] ?? null;

        // data peut être un objet kline ou une liste (multi-kline)
        if (!is_array($payload)) {
            error_log("[KLINE_DEBUG] 'data' missing or not array");
            return;
        }

        // Nouveau format BitMart avec items
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
            if (!empty($items)) {
                $payload = end($items); // Prendre le dernier item
            } else {
                error_log("[KLINE_DEBUG] Empty items array");
                return;
            }
        }
        // Si liste, on prend la dernière
        elseif ($this->isList($payload)) {
            $last = end($payload);
            if ($last !== false && is_array($last)) {
                $payload = $last;
            }
        }

        if (!preg_match('/futures\/klineBin(\w+):([A-Z0-9_]+)/', $group, $m)) {
            error_log("[KLINE_DEBUG] Group pattern not matched: {$group}");
            return;
        }
        $bitmartTf = $m[1];
        $symbol    = $m[2];
        $tf        = $this->convertTimeframeFromBitmart($bitmartTf);

        // === LOG BRUT DU KLINE PARSE ===
        error_log("[KLINE_RAW] group={$group} symbol={$symbol} tf={$tf} data=" . json_encode($payload));

        // Extraction stricte
        $open  = $this->extract($payload, ['o', 'open']);
        $high  = $this->extract($payload, ['h', 'high']);
        $low   = $this->extract($payload, ['l', 'low']);
        $close = $this->extract($payload, ['c', 'close']);
        $vol   = $this->extract($payload, ['v', 'volume']);
        $ts    = $this->extract($payload, ['ts', 'open_time']);

        if ($open === null || $high === null || $low === null || $close === null || $vol === null || $ts === null) {
            fwrite(STDERR, "[KLINE_DEBUG] Missing fields for {$symbol} {$tf}. keys=" . implode(',', array_keys($payload)) . "\n");
            return;
        }

        // Normalisation ts: BitMart en secondes -> ms
        $tsMs = $this->normalizeTsSecondsToMs($ts);
        if ($tsMs === null) {
            fwrite(STDERR, "[KLINE_DEBUG] Invalid ts for {$symbol} {$tf}: {$ts}\n");
            return;
        }

        // Garde anti-frame 0
        if (
            (float)$open  == 0.0 &&
            (float)$high  == 0.0 &&
            (float)$low   == 0.0 &&
            (float)$close == 0.0
        ) {
            error_log("[KLINE_GUARD] Zero-frame ignored {$symbol} {$tf} ts={$tsMs}");
            return;
        }

        // Log valeurs extraites
        fwrite(STDOUT, "[KLINE_DEBUG] {$symbol} {$tf} | O={$open} H={$high} L={$low} C={$close} V={$vol} TS(ms)={$tsMs}\n");

        // Fermeture éventuelle de la bougie précédente
        $prev = $this->lastOpen[$symbol][$tf] ?? null;
        if ($prev !== null && $tsMs > $prev) {
            $this->markPreviousClosed($symbol, $tf, $prev);
        }
        $this->lastOpen[$symbol][$tf] = $tsMs;

        // Persistance hot_kline
        $this->saveKlineToDatabase($symbol, $tf, (string)$open, (string)$high, (string)$low, (string)$close, (string)$vol, $tsMs);
    }

    private function saveKlineToDatabase(
        string $symbol,
        string $tf,
        string $open,
        string $high,
        string $low,
        string $close,
        string $volume,
        int $timestampMs
    ): void {
        if (!$this->pdo) {
            return;
        }

        try {
            $openTime = (new \DateTimeImmutable())->setTimestamp((int) floor($timestampMs / 1000));
            $ohlc = ['o' => $open, 'h' => $high, 'l' => $low, 'c' => $close, 'v' => $volume];

            $sql = "
                INSERT INTO hot_kline (symbol, timeframe, open_time, ohlc, is_closed, last_update)
                VALUES (?, ?, ?, ?, false, NOW())
                ON CONFLICT (symbol, timeframe, open_time)
                DO UPDATE SET
                    ohlc = EXCLUDED.ohlc,
                    is_closed = EXCLUDED.is_closed,
                    last_update = NOW()
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $symbol,
                $tf,
                $openTime->format('Y-m-d H:i:s'),
                json_encode($ohlc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            // === LOG BRUT D'ECRITURE DB ===
            error_log("[HOT_KLINE_UPSERT] ".json_encode([
                'symbol' => $symbol,
                'tf' => $tf,
                'open_time' => $openTime->format('Y-m-d H:i:s'),
                'ohlc' => $ohlc,
                'is_closed' => false,
            ]));

            fwrite(STDOUT, sprintf(
                "[HOT_KLINE] Saved: %s %s | O:%s H:%s L:%s C:%s V:%s\n",
                $symbol, $tf, $open, $high, $low, $close, $volume
            ));

        } catch (\Throwable $e) {
            fwrite(STDERR, "[HOT_KLINE] Database save failed: " . $e->getMessage() . "\n");
            error_log("[HOT_KLINE_ERR] ".$e->getTraceAsString());
        }
    }

    private function markPreviousClosed(string $symbol, string $tf, int $prevTsMs): void
    {
        if (!$this->pdo) {
            return;
        }
        try {
            $prevTime = (new \DateTimeImmutable())->setTimestamp((int) floor($prevTsMs / 1000));
            $sql = "UPDATE hot_kline SET is_closed = true, last_update = NOW()
                    WHERE symbol = ? AND timeframe = ? AND open_time = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$symbol, $tf, $prevTime->format('Y-m-d H:i:s')]);

            error_log("[HOT_KLINE_CLOSE] ".json_encode([
                'symbol' => $symbol,
                'tf' => $tf,
                'open_time' => $prevTime->format('Y-m-d H:i:s'),
                'closed' => true,
            ]));
        } catch (\Throwable $e) {
            fwrite(STDERR, "[HOT_KLINE] Mark previous closed failed: " . $e->getMessage() . "\n");
        }
    }

    /* ===========================
       Utils
       =========================== */

    private function extract(array $arr, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $arr) && $arr[$k] !== null) {
                return (string)$arr[$k];
            }
        }
        return null;
    }

    private function normalizeTsSecondsToMs(string $value): ?int
    {
        if (!is_numeric($value)) return null;
        $n = (float)$value;
        return (int) round($n * 1000);
    }

    private function convertTimeframeFromBitmart(string $bitmartTf): string
    {
        $reverse = array_flip($this->timeframeMapping);
        return $reverse[$bitmartTf] ?? $bitmartTf;
    }

    private function isList(array $a): bool
    {
        // PHP >= 8.1 a array_is_list()
        if (function_exists('array_is_list')) {
            return array_is_list($a);
        }
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }

    private function initDatabase(): void
    {
        try {
            $databaseUrl = $_ENV['DATABASE_URL'] ?? null;
            if (!$databaseUrl) {
                fwrite(STDERR, "[KLINE] DATABASE_URL not configured, persistence disabled\n");
                return;
            }

            $parts = parse_url($databaseUrl);
            if (!$parts) {
                throw new \RuntimeException("Invalid DATABASE_URL format");
            }
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
                $parts['host'] ?? 'localhost',
                $parts['port'] ?? 5432,
                ltrim($parts['path'] ?? '', '/')
            );
            $user = $parts['user'] ?? null;
            $pass = $parts['pass'] ?? null;

            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            fwrite(STDOUT, "[KLINE] Database connection established\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "[KLINE] Database connection failed: " . $e->getMessage() . "\n");
            $this->pdo = null;
        }
    }
}
