<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Service\Config\TradingParameters;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

// @tag:mtf-core  ÉVALUATION & MISE À JOUR SÛRE pour le callback

final class CallbackEvalService
{
    private const DEFAULT_TTL_MINUTES = [
        '4h' => 240,
        '1h' => 20,
        '15m' => 10,
        '5m' => 5,
        '1m' => 1,
    ];

    private const DEFAULT_DESCENT_TARGET = [
        '4h' => '1h',
        '1h' => '15m',
        '15m' => '5m',
        '5m' => '1m',
    ];

    private const DEFAULT_ASCENT_TARGET = [
        '1m' => '15m',
        '5m' => '15m',
        '15m' => '1h',
        '1h' => '4h',
    ];

    private const DEFAULT_ATTEMPTS = [
        '1h' => 3,
        '15m' => 3,
        '5m' => 2,
        '1m' => 4,
    ];//todo: use config

    public function __construct(
        private readonly Connection $db,
        private readonly SlotService $slot,
        private readonly LoggerInterface $logger,
        private readonly IndicatorServiceInterface $indicators,
        private readonly TradingParameters $tradingParameters,
    ) {}

    /** Traite un (symbol, tf) sur le slot aligné courant, avec protections. */
    public function evaluateAndPersist(string $symbol, string $tf): void
    {
        $tf = strtolower($tf);
        $slot = $this->slot->currentSlot($tf);
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (!$this->ensureParentFresh($symbol, $tf, $slot)) {
            return;
        }

        $facts = $this->indicators->evaluate($symbol, $tf, $slot);
        $this->persistEvaluation($symbol, $tf, $slot, $facts, $now);
    }

    public function persistEvaluation(string $symbol, string $tf, \DateTimeImmutable $slot, array $facts, ?\DateTimeImmutable $now = null): void
    {
        $tf = strtolower($tf);
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->db->executeStatement(
            "INSERT INTO signal_events(symbol, tf, slot_start_utc, passed, side, score, at_utc, meta_json)
             VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),?)
             ON DUPLICATE KEY UPDATE
                passed=VALUES(passed), side=VALUES(side), score=VALUES(score),
                at_utc=VALUES(at_utc), meta_json=VALUES(meta_json)",
            [
                $symbol, $tf, $slot->format('Y-m-d H:i:s'),
                (int)$facts['passed'], (string)$facts['side'], $facts['score'],
                json_encode($facts['meta'] ?? []),
            ]
        );

        $this->db->executeStatement(
            "INSERT INTO latest_signal_by_tf(symbol, tf, slot_start_utc, at_utc, side, passed, score, meta_json)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               at_utc         = GREATEST(at_utc, VALUES(at_utc)),
               slot_start_utc = IF(VALUES(at_utc) >= at_utc, VALUES(slot_start_utc), slot_start_utc),
               side           = IF(VALUES(at_utc) >= at_utc, VALUES(side), side),
               passed         = IF(VALUES(at_utc) >= at_utc, VALUES(passed), passed),
               score          = IF(VALUES(at_utc) >= at_utc, VALUES(score), score),
               meta_json      = IF(VALUES(at_utc) >= at_utc, VALUES(meta_json), meta_json)",
            [
                $symbol, $tf, $slot->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'), (string)$facts['side'], (int)$facts['passed'], $facts['score'],
                json_encode($facts['meta'] ?? []),
            ]
        );

        $maxAttempts = $this->resolveMaxAttempts($tf);
        $retryCount = $this->fetchRetryCount($symbol, $tf);
        $ascentTarget = $this->resolveAscentTarget($tf);
        $shouldDAscent = $maxAttempts <= ($retryCount +1);

        $this->db->executeStatement(
            "INSERT INTO tf_retry_status(symbol, tf, retry_count, last_result, updated_at)
             VALUES (?,?,0,'NONE',UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            [$symbol, $tf]
        );

        if ((int)$facts['passed'] === 1 || $ascentTarget) {
            $this->db->executeStatement(
                "UPDATE tf_retry_status SET retry_count=0, last_result=?, updated_at=UTC_TIMESTAMP()
                 WHERE symbol=? AND tf=?",
                [$ascentTarget ? 'SUCCESS': 'FAILED', $symbol, $ascentTarget ? $ascentTarget: $tf]
            );
        } else {
            $this->db->executeStatement(
                "UPDATE tf_retry_status SET retry_count=retry_count+1, last_result='FAILED', updated_at=UTC_TIMESTAMP()
                 WHERE symbol=? AND tf=?",
                [$symbol, $tf]
            );
        }

        $retryCount = $this->fetchRetryCount($symbol, $tf);
        $this->applyEligibilityRouting($symbol, $tf, (bool)$facts['passed'], $retryCount);
    }

    public function ensureParentFresh(string $symbol, string $tf, \DateTimeImmutable $slot): bool
    {
        $parent = $this->slot->parentOf($tf);
        if (!$parent) {
            return true;
        }

        $latestParentSlot = $this->db->fetchOne(
            "SELECT slot_start_utc FROM latest_signal_by_tf WHERE symbol=? AND tf=?",
            [$symbol, $parent]
        );
        $currentParentSlot = $this->slot->currentSlot($parent)->format('Y-m-d H:i:s');
        if ($latestParentSlot && $latestParentSlot >= $currentParentSlot) {
            return true;
        }

        $this->db->executeStatement(
            "INSERT INTO pending_child_signals(symbol, tf, slot_start_utc, payload_json, created_at)
             VALUES (?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json), created_at=VALUES(created_at)",
            [$symbol, $tf, $slot->format('Y-m-d H:i:s'), json_encode(['reason' => 'parent_not_fresh'])]
        );
        $this->logger->info('[callback] parent not fresh → pending', compact('symbol','tf'));
        return false;
    }

    private function applyEligibilityRouting(string $symbol, string $tf, bool $passed, int $retryCount): void
    {
        $tf = strtolower($tf);
        $cooldownMinutes = $this->resolveCooldownMinutes($tf);
        $cooldownUntil = $this->computeCooldownDeadline($tf);
        $maxAttempts = $this->resolveMaxAttempts($tf);
        $backTo = $this->resolveAscentTarget($tf);
        $descendTarget = $this->resolveDescentTarget($tf);
        $shouldAscend = !$passed && $backTo !== null && $this->shouldAscendOnFailure($retryCount, $maxAttempts);

        $this->logger->debug('[pipeline] routing eligibility', [
            'symbol' => $symbol,
            'tf' => $tf,
            'passed' => $passed,
            'retry_count' => $retryCount,
            'cooldown_minutes' => $cooldownMinutes,
            'descend_target' => $descendTarget,
            'back_to' => $backTo,
            'should_ascend' => $shouldAscend,
        ]);

        // cooldown TF courant
        $this->db->executeStatement(
            "INSERT INTO tf_eligibility(symbol, tf, status, priority, cooldown_until, reason, updated_at)
             VALUES (?,?, 'COOLDOWN', 0, ?, 'cooldown_after_eval', UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               status='COOLDOWN', priority=0,
               cooldown_until=VALUES(cooldown_until),
               reason=VALUES(reason), updated_at=VALUES(updated_at)",
            [$symbol, $tf, $cooldownUntil]
        );

        // next TF (descente si succès, remontée sinon)
        $target = $passed ? $descendTarget : ($shouldAscend ? $backTo : null);

        if ($target && $target !== $tf) {
            $this->db->executeStatement(
                "INSERT INTO tf_eligibility(symbol, tf, status, priority, cooldown_until, reason, updated_at)
                 VALUES (?,?, 'ACTIVE', 100, NULL, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   status='ACTIVE', priority=GREATEST(priority,100),
                   cooldown_until=NULL, reason=VALUES(reason), updated_at=VALUES(updated_at)",
                [$symbol, $target, $passed ? 'descend' : 'ascend']
            );
            $this->logger->debug('[pipeline] routed target toggled', [
                'symbol' => $symbol,
                'from_tf' => $tf,
                'target_tf' => $target,
                'mode' => $passed ? 'descend' : ($shouldAscend ? 'ascend' : 'unknown'),
            ]);
        } else {
            $this->logger->debug('[pipeline] routing target unchanged', [
                'symbol' => $symbol,
                'tf' => $tf,
                'target' => $target,
            ]);
        }
    }

    private function resolveCooldownMinutes(string $tf): int
    {
        $wait = $this->tradingParameters->orchestrationWaitMinutes($tf);
        if ($wait !== null && $wait > 0) {
            return $wait;
        }

        return self::DEFAULT_TTL_MINUTES[$tf] ?? 5;
    }

    private function computeCooldownDeadline(string $tf): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $currentSlot = $this->slot->currentSlot($tf, $now);
        $slotLength = $this->slot->slotLengthMinutes($tf);
        $nextSlot = $currentSlot->modify(sprintf('+%d minutes', $slotLength));

        $diffSeconds = max(0, $nextSlot->getTimestamp() - $now->getTimestamp());
        $offsetCeil = (int)max(1, min(10, $diffSeconds));
        $offset = $diffSeconds > 0 ? random_int(1, $offsetCeil) : 1;

        $cooldown = $nextSlot->modify(sprintf('-%d seconds', $offset));
        if ($cooldown <= $now) {
            $cooldown = $now->add(new \DateInterval('PT1S'));
        }

        return $cooldown->format('Y-m-d H:i:s');
    }

    private function resolveMaxAttempts(string $tf): int
    {
        $retry = $this->tradingParameters->orchestrationRetryFor($tf);
        if ($retry !== null && isset($retry['attempts']) && (int)$retry['attempts'] > 0) {
            return (int)$retry['attempts'];
        }

        return self::DEFAULT_ATTEMPTS[$tf] ?? 1;
    }

    private function resolveAscentTarget(string $tf): ?string
    {
        $retry = $this->tradingParameters->orchestrationRetryFor($tf);
        $back = $retry['back_to'] ?? null;
        if (is_string($back) && $back !== '') {
            return strtolower($back);
        }

        return self::DEFAULT_ASCENT_TARGET[$tf] ?? null;
    }

    private function resolveDescentTarget(string $tf): ?string
    {
        return self::DEFAULT_DESCENT_TARGET[$tf] ?? null;
    }

    private function shouldAscendOnFailure(int $retryCount, int $maxAttempts): bool
    {
        if ($maxAttempts <= 0) {
            return true;
        }

        return $retryCount >= $maxAttempts;
    }

    private function fetchRetryCount(string $symbol, string $tf): int
    {
        $value = $this->db->fetchOne(
            "SELECT retry_count FROM tf_retry_status WHERE symbol=? AND tf=?",
            [$symbol, $tf]
        );

        if ($value === null || $value === false) {
            return 0;
        }

        return (int)$value;
    }
}
