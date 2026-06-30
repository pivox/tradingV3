<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HyperliquidNonceState;
use App\Provider\Hyperliquid\HyperliquidNonceReplayException;
use App\Provider\Hyperliquid\HyperliquidNonceScope;
use App\Provider\Hyperliquid\HyperliquidNonceScopeConflictException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HyperliquidNonceState>
 */
final class HyperliquidNonceStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HyperliquidNonceState::class);
    }

    public function reserveNext(HyperliquidNonceScope $scope, int $candidateNonce, \DateTimeImmutable $now): int
    {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
INSERT INTO hyperliquid_nonce_state (
    environment,
    network,
    account_address,
    signer_address,
    last_nonce,
    created_at,
    updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?)
ON CONFLICT (environment, network, signer_address)
DO UPDATE SET
    last_nonce = CASE
        WHEN hyperliquid_nonce_state.last_nonce + 1 > EXCLUDED.last_nonce THEN hyperliquid_nonce_state.last_nonce + 1
        ELSE EXCLUDED.last_nonce
    END,
    updated_at = EXCLUDED.updated_at
WHERE hyperliquid_nonce_state.account_address = EXCLUDED.account_address
RETURNING last_nonce
SQL,
            $this->parameters($scope, $candidateNonce, $now),
            $this->parameterTypes(),
        );

        $reserved = $result->fetchOne();
        if ($reserved === false) {
            throw new HyperliquidNonceScopeConflictException();
        }

        return (int) $reserved;
    }

    public function recordObserved(HyperliquidNonceScope $scope, int $observedNonce, \DateTimeImmutable $now): void
    {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
INSERT INTO hyperliquid_nonce_state (
    environment,
    network,
    account_address,
    signer_address,
    last_nonce,
    created_at,
    updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?)
ON CONFLICT (environment, network, signer_address)
DO UPDATE SET
    last_nonce = EXCLUDED.last_nonce,
    updated_at = EXCLUDED.updated_at
WHERE hyperliquid_nonce_state.account_address = EXCLUDED.account_address
  AND hyperliquid_nonce_state.last_nonce < EXCLUDED.last_nonce
RETURNING last_nonce
SQL,
            $this->parameters($scope, $observedNonce, $now),
            $this->parameterTypes(),
        );

        if ($result->fetchOne() !== false) {
            return;
        }

        $accountAddress = $this->accountAddressForSigner($scope);
        if ($accountAddress !== null && $accountAddress !== $scope->accountAddress) {
            throw new HyperliquidNonceScopeConflictException();
        }

        throw new HyperliquidNonceReplayException();
    }

    private function accountAddressForSigner(HyperliquidNonceScope $scope): ?string
    {
        $accountAddress = $this->createQueryBuilder('state')
            ->select('state.accountAddress')
            ->andWhere('state.environment = :environment')
            ->andWhere('state.network = :network')
            ->andWhere('state.signerAddress = :signerAddress')
            ->setParameter('environment', $scope->environment)
            ->setParameter('network', $scope->network)
            ->setParameter('signerAddress', $scope->signerAddress)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($accountAddress === null) {
            return null;
        }

        if (!is_array($accountAddress) || !isset($accountAddress['accountAddress']) || !is_string($accountAddress['accountAddress'])) {
            throw new HyperliquidNonceReplayException();
        }

        return $accountAddress['accountAddress'];
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:int,5:\DateTimeImmutable,6:\DateTimeImmutable}
     */
    private function parameters(HyperliquidNonceScope $scope, int $nonce, \DateTimeImmutable $now): array
    {
        return [
            $scope->environment,
            $scope->network,
            $scope->accountAddress,
            $scope->signerAddress,
            $nonce,
            $now,
            $now,
        ];
    }

    /**
     * @return list<string>
     */
    private function parameterTypes(): array
    {
        return [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::BIGINT,
            Types::DATETIMETZ_IMMUTABLE,
            Types::DATETIMETZ_IMMUTABLE,
        ];
    }
}
