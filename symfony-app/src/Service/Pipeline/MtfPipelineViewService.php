<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use Doctrine\DBAL\Connection;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

final class MtfPipelineViewService
{
    private const TF_ORDER = ['4h','1h','15m','5m','1m'];
    private const DEFAULT_MAX_RETRIES = [
        '4h' => 1,
        '1h' => 3,
        '15m' => 3,
        '5m' => 2,
        '1m' => 4,
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ContractRepository $contracts,
        private readonly KlineRepository $klines,
        private readonly SlotService $slotService,
        private readonly LoggerInterface $logger,
        private readonly TradingParameters $tradingParameters,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(?string $statusFilter = null): array
    {
        $pipelines = $this->collect();
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $pipelines = array_filter($pipelines, static function(array $row) use ($statusFilter) {
                return $row['card_status'] === $statusFilter;
            });
        }
        uasort($pipelines, static function(array $a, array $b): int {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });
        return array_values($pipelines);
    }

    public function get(string $symbol): ?array
    {
        $symbol = strtoupper($symbol);
        $pipelines = $this->collect();
        return $pipelines[$symbol] ?? null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function preview(int $limit = 5): array
    {
        $pipelines = $this->collect();
        $candidates = array_filter($pipelines, static function(array $row) {
            return $row['card_status'] !== 'completed';
        });
        uasort($candidates, static function(array $a, array $b): int {
            return strcmp($a['card_status'] ?? '', $b['card_status'] ?? '');
        });
        $candidates = array_slice($candidates, 0, $limit);
        foreach ($candidates as &$item) {
            $tf = $item['current_timeframe'] ?? '15m';
            $window = $this->slotService->currentSlot($tf);
            $klines = $this->klines->findRecentBySymbolAndTimeframe($item['symbol'], $tf, 120);
            $item['klines'] = array_map(static function($kline) {
                if (!is_object($kline)) {
                    return $kline;
                }
                return [
                    'timestamp' => $kline->getTimestamp()?->getTimestamp(),
                    'open' => $kline->getOpen(),
                    'close' => $kline->getClose(),
                    'high' => $kline->getHigh(),
                    'low' => $kline->getLow(),
                    'volume' => $kline->getVolume(),
                ];
            }, $klines);
            $item['slot'] = $window->format(DateTimeImmutable::ATOM);
        }
        return array_values($candidates);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function collect(): array
    {
        $signalsRows = $this->db->fetchAllAssociative('SELECT symbol, tf, slot_start_utc, side, passed, score, meta_json, at_utc FROM latest_signal_by_tf');
        $eligibilityRows = $this->db->fetchAllAssociative('SELECT symbol, tf, status, priority, cooldown_until, reason, updated_at FROM tf_eligibility');
        $retryRows = $this->db->fetchAllAssociative('SELECT symbol, tf, retry_count, last_result, updated_at FROM tf_retry_status');
        $orderRows = $this->db->fetchAllAssociative('SELECT symbol, order_id, intent, created_at FROM outgoing_orders ORDER BY created_at DESC');
        $pendingRows = $this->db->fetchAllAssociative('SELECT symbol, tf, slot_start_utc, payload_json FROM pending_child_signals');

        $symbols = [];
        foreach ($signalsRows as $row) { $symbols[strtoupper((string)$row['symbol'])] = true; }
        foreach ($eligibilityRows as $row) { $symbols[strtoupper((string)$row['symbol'])] = true; }
        foreach ($retryRows as $row) { $symbols[strtoupper((string)$row['symbol'])] = true; }
        foreach ($orderRows as $row) { $symbols[strtoupper((string)$row['symbol'])] = true; }
        foreach ($pendingRows as $row) { $symbols[strtoupper((string)$row['symbol'])] = true; }

        $pipelines = [];
        foreach (array_keys($symbols) as $symbol) {
            $pipelines[$symbol] = [
                'symbol' => $symbol,
                'signals' => [],
                'eligibility' => [],
                'retries' => [],
                'pending_children' => [],
                'order_id' => null,
            ];
        }

        foreach ($signalsRows as $row) {
            $symbol = strtoupper((string)$row['symbol']);
            $tf = strtolower((string)$row['tf']);
            $meta = [];
            if ($row['meta_json'] !== null) {
                $decoded = json_decode((string)$row['meta_json'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $pipelines[$symbol]['signals'][$tf] = [
                'signal' => strtoupper((string)$row['side']),
                'passed' => (bool)$row['passed'],
                'score' => $row['score'] !== null ? (float)$row['score'] : null,
                'slot_start' => $row['slot_start_utc'] ? new DateTimeImmutable((string)$row['slot_start_utc']) : null,
                'at_utc' => $row['at_utc'] ? new DateTimeImmutable((string)$row['at_utc']) : null,
                'meta' => $meta,
            ];
        }

        foreach ($eligibilityRows as $row) {
            $symbol = strtoupper((string)$row['symbol']);
            $tf = strtolower((string)$row['tf']);
            $pipelines[$symbol]['eligibility'][$tf] = [
                'status' => strtoupper((string)$row['status']),
                'priority' => (int)$row['priority'],
                'cooldown_until' => $row['cooldown_until'] ? new DateTimeImmutable((string)$row['cooldown_until']) : null,
                'reason' => $row['reason'],
                'updated_at' => $row['updated_at'] ? new DateTimeImmutable((string)$row['updated_at']) : null,
            ];
        }

        foreach ($retryRows as $row) {
            $symbol = strtoupper((string)$row['symbol']);
            $tf = strtolower((string)$row['tf']);
            $pipelines[$symbol]['retries'][$tf] = [
                'retry_count' => (int)$row['retry_count'],
                'last_result' => strtoupper((string)$row['last_result']),
                'updated_at' => $row['updated_at'] ? new DateTimeImmutable((string)$row['updated_at']) : null,
            ];
        }

        foreach ($orderRows as $row) {
            $symbol = strtoupper((string)$row['symbol']);
            if (!isset($pipelines[$symbol])) {
                continue;
            }
            if ($pipelines[$symbol]['order_id'] === null) {
                $pipelines[$symbol]['order_id'] = (string)$row['order_id'];
            }
        }

        foreach ($pendingRows as $row) {
            $symbol = strtoupper((string)$row['symbol']);
            $tf = strtolower((string)$row['tf']);
            $payload = [];
            if ($row['payload_json'] !== null) {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $pipelines[$symbol]['pending_children'][$tf] = $payload;
        }

        foreach ($pipelines as $symbol => &$data) {
            $contract = $this->contracts->find($symbol);
            $data['contract'] = $contract ? [
                'symbol' => $contract->getSymbol(),
                'exchange' => $contract->getExchange()?->getName(),
            ] : ['symbol' => $symbol, 'exchange' => null];
            $data['current_timeframe'] = $this->determineCurrentTimeframe($data['eligibility']);
            $data['max_retries'] = $this->maxRetriesFor($data['current_timeframe']);
            $data['retries_current'] = $data['retries'][$data['current_timeframe']]['retry_count'] ?? 0;
            $data['card_status'] = $this->determineCardStatus($data);
            $data['updated_at'] = $this->determineUpdatedAt($data);
            $data['last_attempt_at'] = $this->determineLastAttempt($data['signals']);
        }
        unset($data);

        return $pipelines;
    }

    private function determineCurrentTimeframe(array $eligibility): string
    {
        $bestTf = '4h';
        $bestPriority = -INF;
        foreach ($eligibility as $tf => $row) {
            $priority = (int)($row['priority'] ?? 0);
            $status = (string)($row['status'] ?? '');
            if ($priority > $bestPriority && $status !== '') {
                $bestPriority = $priority;
                $bestTf = $tf;
            }
        }
        return $bestTf;
    }

    private function determineCardStatus(array $pipeline): string
    {
        $signals = $pipeline['signals'];
        $eligibility = $pipeline['eligibility'];
        $retries = $pipeline['retries'];
        foreach ($eligibility as $tf => $row) {
            if (in_array($row['status'], ['LOCKED_POSITION','LOCKED_ORDER'], true)) {
                return 'completed';
            }
        }
        $finalSignal = null;
        foreach (array_reverse(self::TF_ORDER) as $tf) {
            if (isset($signals[$tf])) {
                $side = $signals[$tf]['signal'];
                if ($side !== 'NONE') {
                    $finalSignal = $side;
                    break;
                }
            }
        }
        if (in_array($finalSignal, ['LONG','SHORT'], true)) {
            return 'completed';
        }
        foreach ($retries as $tf => $row) {
            if (($row['last_result'] ?? null) === 'FAILED' && ($row['retry_count'] ?? 0) >= $this->maxRetriesFor($tf)) {
                return 'failed';
            }
        }
        return 'in-progress';
    }

    private function maxRetriesFor(string $tf): int
    {
        $tf = strtolower($tf);
        $retry = $this->tradingParameters->orchestrationRetryFor($tf);
        if ($retry !== null && isset($retry['attempts']) && (int)$retry['attempts'] > 0) {
            return (int)$retry['attempts'];
        }

        return self::DEFAULT_MAX_RETRIES[$tf] ?? 0;
    }

    private function determineUpdatedAt(array $pipeline): ?string
    {
        $dates = [];
        foreach ($pipeline['signals'] as $sig) {
            if (!empty($sig['at_utc'])) {
                $dates[] = $sig['at_utc'];
            }
        }
        foreach ($pipeline['eligibility'] as $elig) {
            if (!empty($elig['updated_at'])) {
                $dates[] = $elig['updated_at'];
            }
        }
        foreach ($pipeline['retries'] as $retry) {
            if (!empty($retry['updated_at'])) {
                $dates[] = $retry['updated_at'];
            }
        }
        if ($dates === []) {
            return null;
        }
        usort($dates, static fn($a, $b) => $a <=> $b);
        return end($dates)?->format(DateTimeImmutable::ATOM);
    }

    private function determineLastAttempt(array $signals): ?string
    {
        $latest = null;
        foreach ($signals as $signal) {
            if (!empty($signal['slot_start'])) {
                if ($latest === null || $signal['slot_start'] > $latest) {
                    $latest = $signal['slot_start'];
                }
            }
        }
        return $latest?->format(DateTimeImmutable::ATOM);
    }
}
