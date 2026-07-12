<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HyperliquidTestnetKillSwitchState;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/** @extends ServiceEntityRepository<HyperliquidTestnetKillSwitchState> */
final class HyperliquidTestnetKillSwitchStateRepository extends ServiceEntityRepository implements HyperliquidKillSwitchTripInterface
{
    private const MAX_REASON_LENGTH = 128;
    private const MAX_VALUE_LENGTH = 128;
    private const MAX_CONTEXT_BYTES = 4_096;
    private const MAX_ITEMS = 16;
    private const MAX_DEPTH = 3;
    private const SENSITIVE_KEY_PATTERN = '/(?:raw|payload|secret|token|api[_-]?key|private[_-]?key|passphrase|password|authorization|cookie|signature|credential|memo)/i';

    public function __construct(ManagerRegistry $registry, private readonly ?ClockInterface $clock = null)
    {
        parent::__construct($registry, HyperliquidTestnetKillSwitchState::class);
    }

    public function isTripped(): bool
    {
        $tripped = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT tripped FROM hyperliquid_testnet_kill_switch_state WHERE scope = ?',
            [HyperliquidTestnetKillSwitchState::SCOPE],
            [Types::STRING],
        );

        return in_array($tripped, [true, 1, '1'], true);
    }

    public function trip(string $reason, array $auditContext): void
    {
        $now = $this->now();
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
INSERT INTO hyperliquid_testnet_kill_switch_state (
    scope,
    tripped,
    reason,
    audit_context,
    tripped_at,
    updated_at
) VALUES (?, TRUE, ?, ?, ?, ?)
ON CONFLICT (scope)
DO UPDATE SET
    tripped = TRUE,
    updated_at = CASE
        WHEN hyperliquid_testnet_kill_switch_state.updated_at > EXCLUDED.updated_at
            THEN hyperliquid_testnet_kill_switch_state.updated_at
        ELSE EXCLUDED.updated_at
    END
SQL,
            [
                HyperliquidTestnetKillSwitchState::SCOPE,
                $this->boundedReason($reason),
                $this->boundedContext($auditContext),
                $now,
                $now,
            ],
            [Types::STRING, Types::STRING, Types::JSON, Types::DATETIMETZ_IMMUTABLE, Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function currentReason(): ?string
    {
        $reason = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT reason FROM hyperliquid_testnet_kill_switch_state WHERE scope = ?',
            [HyperliquidTestnetKillSwitchState::SCOPE],
            [Types::STRING],
        );

        return is_string($reason) ? $reason : null;
    }

    /** @return array<string, mixed> */
    public function currentAuditContext(): array
    {
        $context = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT audit_context FROM hyperliquid_testnet_kill_switch_state WHERE scope = ?',
            [HyperliquidTestnetKillSwitchState::SCOPE],
            [Types::STRING],
        );
        if (!is_string($context)) {
            return [];
        }

        try {
            $decoded = json_decode($context, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function boundedReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || preg_match(self::SENSITIVE_KEY_PATTERN, $reason) === 1) {
            return 'hyperliquid_kill_switch_tripped';
        }

        return substr($reason, 0, self::MAX_REASON_LENGTH);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function boundedContext(array $context): array
    {
        $bounded = $this->sanitizeArray($context, 0);
        $encoded = json_encode($bounded);
        if (is_string($encoded) && strlen($encoded) <= self::MAX_CONTEXT_BYTES) {
            return $bounded;
        }

        $correlationId = $bounded['correlation_id'] ?? null;

        return is_string($correlationId) ? ['correlation_id' => $correlationId] : [];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice($values, 0, self::MAX_ITEMS, true) as $key => $value) {
            if (!is_string($key) || preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                continue;
            }

            $key = substr(trim($key), 0, 64);
            if ($key === '') {
                continue;
            }
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $sanitized[$key] = substr($value, 0, self::MAX_VALUE_LENGTH);
            } elseif (is_int($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            } elseif (is_float($value) && is_finite($value)) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
