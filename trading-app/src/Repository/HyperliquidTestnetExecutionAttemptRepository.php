<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HyperliquidTestnetExecutionAttempt;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionAttemptClaim;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionAttemptStoreInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchAuditSanitizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HyperliquidTestnetExecutionAttempt> */
final class HyperliquidTestnetExecutionAttemptRepository extends ServiceEntityRepository implements HyperliquidExecutionAttemptStoreInterface
{
    private const SCOPE = 'hyperliquid_testnet';
    private const ACTIVE_STATES = ['reserved', 'submitted', 'compensating'];
    private const TERMINAL_PREFIX = 'terminal_';

    public function __construct(
        ManagerRegistry $registry,
        private readonly ?HyperliquidKillSwitchAuditSanitizer $sanitizer = null,
    )
    {
        parent::__construct($registry, HyperliquidTestnetExecutionAttempt::class);
    }

    public function claim(
        string $idempotencyKey,
        string $planFingerprint,
        string $clientOrderId,
        string $correlationId,
    ): HyperliquidExecutionAttemptClaim {
        $this->validateIdentifiers($idempotencyKey, $planFingerprint, $clientOrderId, $correlationId);
        $connection = $this->getEntityManager()->getConnection();

        return $connection->transactional(function (Connection $connection) use ($idempotencyKey, $planFingerprint, $clientOrderId, $correlationId): HyperliquidExecutionAttemptClaim {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $inserted = $connection->executeStatement(
                <<<'SQL'
INSERT INTO hyperliquid_testnet_execution_attempt (
    idempotency_key, scope, active_slot, plan_fingerprint, client_order_id,
    correlation_id, state, result_payload, created_at, updated_at
) VALUES (?, ?, 1, ?, ?, ?, 'reserved', NULL, ?, ?)
ON CONFLICT DO NOTHING
SQL,
                [$idempotencyKey, self::SCOPE, $planFingerprint, $clientOrderId, $correlationId, $now, $now],
                [Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::DATETIMETZ_IMMUTABLE, Types::DATETIMETZ_IMMUTABLE],
            );
            if ($inserted === 1) {
                return new HyperliquidExecutionAttemptClaim(HyperliquidExecutionAttemptClaim::CLAIMED);
            }

            $lock = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
            $row = $connection->fetchAssociative(
                'SELECT plan_fingerprint, client_order_id, state, result_payload FROM hyperliquid_testnet_execution_attempt WHERE idempotency_key = ?' . $lock,
                [$idempotencyKey],
                [Types::STRING],
            );
            if (!is_array($row)) {
                return new HyperliquidExecutionAttemptClaim(HyperliquidExecutionAttemptClaim::GLOBAL_ACTIVE);
            }
            if (!is_string($row['plan_fingerprint'] ?? null)
                || !hash_equals($row['plan_fingerprint'], $planFingerprint)
                || !is_string($row['client_order_id'] ?? null)
                || !hash_equals($row['client_order_id'], $clientOrderId)
            ) {
                return new HyperliquidExecutionAttemptClaim(HyperliquidExecutionAttemptClaim::CONFLICT);
            }
            $state = $row['state'] ?? null;
            if (is_string($state) && in_array($state, self::ACTIVE_STATES, true)) {
                return new HyperliquidExecutionAttemptClaim(HyperliquidExecutionAttemptClaim::ACTIVE_REPLAY);
            }
            if (!is_string($state) || !str_starts_with($state, self::TERMINAL_PREFIX)) {
                throw new \RuntimeException('hyperliquid_execution_attempt_state_invalid');
            }

            return new HyperliquidExecutionAttemptClaim(
                HyperliquidExecutionAttemptClaim::TERMINAL_REPLAY,
                $this->decodeResult($row['result_payload'] ?? null, $state),
            );
        });
    }

    public function transition(string $idempotencyKey, string $planFingerprint, string $state): void
    {
        if (!in_array($state, ['submitted', 'compensating'], true)) {
            throw new \InvalidArgumentException('hyperliquid_execution_attempt_transition_invalid');
        }
        $sourceStates = $state === 'submitted'
            ? "('reserved', 'submitted')"
            : "('submitted', 'compensating')";
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE hyperliquid_testnet_execution_attempt SET state = ?, updated_at = ? WHERE idempotency_key = ? AND plan_fingerprint = ? AND state IN {$sourceStates}",
            [$state, $now, $idempotencyKey, $planFingerprint],
            [Types::STRING, Types::DATETIMETZ_IMMUTABLE, Types::STRING, Types::STRING],
        );
        if ($updated !== 1) {
            throw new \RuntimeException('hyperliquid_execution_attempt_state_conflict');
        }
    }

    public function complete(string $idempotencyKey, string $planFingerprint, ExecutionResult $result): void
    {
        $sourceStates = match ($result->status) {
            ExecutionStatus::Accepted => "('submitted')",
            ExecutionStatus::Rejected => "('reserved', 'submitted', 'compensating')",
            ExecutionStatus::Failed => "('submitted', 'compensating')",
            default => throw new \InvalidArgumentException('hyperliquid_execution_attempt_terminal_status_invalid'),
        };
        $payload = [
            'status' => $result->status->value,
            'client_order_id' => $result->clientOrderId,
            'exchange_order_id' => $result->exchangeOrderId,
            'metadata' => ($this->sanitizer ?? new HyperliquidKillSwitchAuditSanitizer())->sanitizeContext($result->metadata),
        ];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE hyperliquid_testnet_execution_attempt SET state = ?, active_slot = NULL, result_payload = ?, updated_at = ? WHERE idempotency_key = ? AND plan_fingerprint = ? AND state IN {$sourceStates}",
            ['terminal_' . $result->status->value, $payload, $now, $idempotencyKey, $planFingerprint],
            [Types::STRING, Types::JSON, Types::DATETIMETZ_IMMUTABLE, Types::STRING, Types::STRING],
        );
        if ($updated !== 1) {
            throw new \RuntimeException('hyperliquid_execution_attempt_state_conflict');
        }
    }

    private function validateIdentifiers(string $key, string $fingerprint, string $clientOrderId, string $correlationId): void
    {
        $opaque = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';
        if (preg_match($opaque, $key) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || preg_match($opaque, $clientOrderId) !== 1 || preg_match($opaque, $correlationId) !== 1
        ) {
            throw new \InvalidArgumentException('hyperliquid_execution_attempt_identifier_invalid');
        }
    }

    private function decodeResult(mixed $payload, string $state): ExecutionResult
    {
        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \RuntimeException('hyperliquid_execution_attempt_result_invalid');
            }
        }
        if (!is_array($payload) || array_is_list($payload)
            || !is_string($payload['status'] ?? null)
            || !is_array($payload['metadata'] ?? null)
            || ($payload['metadata'] !== [] && array_is_list($payload['metadata']))
        ) {
            throw new \RuntimeException('hyperliquid_execution_attempt_result_invalid');
        }
        $status = ExecutionStatus::tryFrom($payload['status']);
        $clientOrderId = $payload['client_order_id'] ?? null;
        $exchangeOrderId = $payload['exchange_order_id'] ?? null;
        if (!$status instanceof ExecutionStatus
            || $state !== 'terminal_' . $status->value
            || ($clientOrderId !== null && !is_string($clientOrderId))
            || ($exchangeOrderId !== null && !is_string($exchangeOrderId))
        ) {
            throw new \RuntimeException('hyperliquid_execution_attempt_result_invalid');
        }

        return new ExecutionResult($status, $clientOrderId, $exchangeOrderId, [], $payload['metadata']);
    }
}
