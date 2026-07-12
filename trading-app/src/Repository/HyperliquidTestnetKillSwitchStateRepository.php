<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HyperliquidTestnetKillSwitchState;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchAuditSanitizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/** @extends ServiceEntityRepository<HyperliquidTestnetKillSwitchState> */
final class HyperliquidTestnetKillSwitchStateRepository extends ServiceEntityRepository implements HyperliquidKillSwitchTripInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ?ClockInterface $clock = null,
        private readonly ?HyperliquidKillSwitchAuditSanitizer $sanitizer = null,
    )
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
                $this->auditSanitizer()->sanitizeReason($reason),
                $this->auditSanitizer()->sanitizeContext($auditContext),
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

    private function auditSanitizer(): HyperliquidKillSwitchAuditSanitizer
    {
        return $this->sanitizer ?? new HyperliquidKillSwitchAuditSanitizer();
    }
}
