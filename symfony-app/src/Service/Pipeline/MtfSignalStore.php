<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final class MtfSignalStore
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array<string,array{signal:string,passed:bool,score:float|null,meta:array,slot_start?:DateTimeImmutable}>
     */
    public function fetchLatestSignals(string $symbol): array
    {
        $sql = <<<SQL
SELECT tf, side, passed, score, meta_json, slot_start_utc
FROM latest_signal_by_tf
WHERE symbol = :symbol
SQL;
        $rows = $this->db->fetchAllAssociative($sql, ['symbol' => $symbol]);
        $result = [];
        foreach ($rows as $row) {
            $tf = strtolower((string)$row['tf']);
            $meta = [];
            if ($row['meta_json'] !== null) {
                $decoded = json_decode((string)$row['meta_json'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $slot = null;
            if (!empty($row['slot_start_utc'])) {
                $slot = new DateTimeImmutable((string)$row['slot_start_utc']);
            }
            $result[$tf] = [
                'signal' => strtoupper((string)$row['side']),
                'passed' => (bool)$row['passed'],
                'score' => $row['score'] !== null ? (float)$row['score'] : null,
                'meta' => $meta,
                'slot_start' => $slot,
            ];
        }
        return $result;
    }

    /**
     * @return array<string,array{status:string,priority:int,cooldown_until:?DateTimeImmutable,reason:?string}>
     */
    public function fetchEligibility(string $symbol): array
    {
        $sql = <<<SQL
SELECT tf, status, priority, cooldown_until, reason
FROM tf_eligibility
WHERE symbol = :symbol
SQL;
        $rows = $this->db->fetchAllAssociative($sql, ['symbol' => $symbol]);
        $result = [];
        foreach ($rows as $row) {
            $tf = strtolower((string)$row['tf']);
            $cooldown = null;
            if (!empty($row['cooldown_until'])) {
                $cooldown = new DateTimeImmutable((string)$row['cooldown_until']);
            }
            $result[$tf] = [
                'status' => (string)$row['status'],
                'priority' => (int)$row['priority'],
                'cooldown_until' => $cooldown,
                'reason' => $row['reason'] !== null ? (string)$row['reason'] : null,
            ];
        }
        return $result;
    }

    /**
     * @return array<string,array{retry_count:int,last_result:string,updated_at:?DateTimeImmutable}>
     */
    public function fetchRetries(string $symbol): array
    {
        $sql = <<<SQL
SELECT tf, retry_count, last_result, updated_at
FROM tf_retry_status
WHERE symbol = :symbol
SQL;
        $rows = $this->db->fetchAllAssociative($sql, ['symbol' => $symbol]);
        $result = [];
        foreach ($rows as $row) {
            $tf = strtolower((string)$row['tf']);
            $updatedAt = null;
            if (!empty($row['updated_at'])) {
                $updatedAt = new DateTimeImmutable((string)$row['updated_at']);
            }
            $result[$tf] = [
                'retry_count' => (int)$row['retry_count'],
                'last_result' => (string)$row['last_result'],
                'updated_at' => $updatedAt,
            ];
        }
        return $result;
    }
}
