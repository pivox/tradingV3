<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\HyperliquidTestnetExecutionAttempt;
use App\Repository\HyperliquidTestnetExecutionAttemptRepository;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionAttemptClaim;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionAttemptStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(HyperliquidTestnetExecutionAttempt::class)]
#[CoversClass(HyperliquidTestnetExecutionAttemptRepository::class)]
final class HyperliquidTestnetExecutionAttemptRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private HyperliquidTestnetExecutionAttemptRepository $repository;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');
        $this->entityManager = $entityManager;
        $metadata = [$entityManager->getClassMetadata(HyperliquidTestnetExecutionAttempt::class)];
        $tool = new SchemaTool($entityManager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->repository = $this->newRepository();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            (new SchemaTool($this->entityManager))->dropSchema([
                $this->entityManager->getClassMetadata(HyperliquidTestnetExecutionAttempt::class),
            ]);
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testClaimIsAtomicAndActiveReplayNeverClaimsAgain(): void
    {
        self::assertInstanceOf(HyperliquidExecutionAttemptStoreInterface::class, $this->repository);

        $first = $this->repository->claim('decision:btc:long', str_repeat('a', 64), 'CID-1', 'corr-1');
        $replay = $this->newRepository()->claim('decision:btc:long', str_repeat('a', 64), 'CID-1', 'corr-1');

        self::assertSame(HyperliquidExecutionAttemptClaim::CLAIMED, $first->outcome);
        self::assertSame(HyperliquidExecutionAttemptClaim::ACTIVE_REPLAY, $replay->outcome);
        self::assertNull($replay->result);
    }

    public function testSameKeyWithDifferentPlanOrClientOrderIdIsConflict(): void
    {
        $this->repository->claim('decision:btc:long', str_repeat('a', 64), 'CID-1', 'corr-1');

        $differentPlan = $this->repository->claim('decision:btc:long', str_repeat('b', 64), 'CID-1', 'corr-2');
        $differentClient = $this->repository->claim('decision:btc:long', str_repeat('a', 64), 'CID-2', 'corr-2');

        self::assertSame(HyperliquidExecutionAttemptClaim::CONFLICT, $differentPlan->outcome);
        self::assertSame(HyperliquidExecutionAttemptClaim::CONFLICT, $differentClient->outcome);
    }

    public function testDifferentKeyCannotClaimWhileAnyAttemptIsActive(): void
    {
        $this->repository->claim('decision:btc:long', str_repeat('a', 64), 'CID-1', 'corr-1');

        $other = $this->newRepository()->claim('decision:eth:long', str_repeat('b', 64), 'CID-2', 'corr-2');

        self::assertSame(HyperliquidExecutionAttemptClaim::GLOBAL_ACTIVE, $other->outcome);
    }

    public function testTerminalAttemptReleasesGlobalSlotForNextKey(): void
    {
        $firstKey = 'decision:btc:long';
        $firstHash = str_repeat('a', 64);
        $this->repository->claim($firstKey, $firstHash, 'CID-1', 'corr-1');
        $this->repository->transition($firstKey, $firstHash, 'submitted');
        $this->repository->complete($firstKey, $firstHash, new ExecutionResult(ExecutionStatus::Rejected, 'CID-1'));

        $other = $this->newRepository()->claim('decision:eth:long', str_repeat('b', 64), 'CID-2', 'corr-2');

        self::assertSame(HyperliquidExecutionAttemptClaim::CLAIMED, $other->outcome);
    }

    public function testTerminalResultSurvivesRestartAndIsReturnedOnExactReplay(): void
    {
        $key = 'decision:btc:long';
        $fingerprint = str_repeat('a', 64);
        $this->repository->claim($key, $fingerprint, 'CID-1', 'corr-1');
        $this->repository->transition($key, $fingerprint, 'submitted');
        $this->repository->complete($key, $fingerprint, new ExecutionResult(
            ExecutionStatus::Accepted,
            'CID-1',
            '42',
            [],
            ['protection_confirmed' => true, 'correlation_id' => 'corr-1'],
        ));

        $this->entityManager->clear();
        $replay = $this->newRepository()->claim($key, $fingerprint, 'CID-1', 'corr-2');

        self::assertSame(HyperliquidExecutionAttemptClaim::TERMINAL_REPLAY, $replay->outcome);
        self::assertEquals(new ExecutionResult(
            ExecutionStatus::Accepted,
            'CID-1',
            '42',
            [],
            ['protection_confirmed' => true, 'correlation_id' => 'corr-1'],
        ), $replay->result);
    }

    public function testInvalidTransitionAndMismatchedFingerprintFailClosed(): void
    {
        $this->repository->claim('decision:btc:long', str_repeat('a', 64), 'CID-1', 'corr-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_execution_attempt_state_conflict');
        $this->repository->transition('decision:btc:long', str_repeat('b', 64), 'submitted');
    }

    public function testStateMachineRejectsRegressionAndAcceptedFromReserved(): void
    {
        $key = 'decision:btc:long';
        $fingerprint = str_repeat('a', 64);
        $this->repository->claim($key, $fingerprint, 'CID-1', 'corr-1');

        try {
            $this->repository->complete($key, $fingerprint, new ExecutionResult(ExecutionStatus::Accepted, 'CID-1', '42'));
            self::fail('Accepted completion from reserved must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_execution_attempt_state_conflict', $exception->getMessage());
        }

        $this->repository->transition($key, $fingerprint, 'submitted');
        $this->repository->transition($key, $fingerprint, 'compensating');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_execution_attempt_state_conflict');
        $this->repository->transition($key, $fingerprint, 'submitted');
    }

    public function testTerminalPayloadNeverPersistsSecretsOrRawResponses(): void
    {
        $key = 'decision:btc:long';
        $fingerprint = str_repeat('a', 64);
        $secret = 'never-persist-this-secret';
        $this->repository->claim($key, $fingerprint, 'CID-1', 'corr-1');
        $this->repository->transition($key, $fingerprint, 'submitted');
        $this->repository->complete($key, $fingerprint, new ExecutionResult(
            ExecutionStatus::Failed,
            'CID-1',
            null,
            ['raw_exchange_response' => $secret],
            ['correlation_id' => 'corr-1', 'private_key' => $secret, 'raw_payload' => $secret],
        ));

        $stored = $this->entityManager->getConnection()->fetchOne(
            'SELECT result_payload FROM hyperliquid_testnet_execution_attempt WHERE idempotency_key = ?',
            [$key],
        );

        self::assertIsString($stored);
        self::assertStringNotContainsString($secret, $stored);
        self::assertStringNotContainsString('raw_exchange_response', $stored);
        self::assertStringNotContainsString('private_key', $stored);
        self::assertStringNotContainsString('raw_payload', $stored);
    }

    private function newRepository(): HyperliquidTestnetExecutionAttemptRepository
    {
        /** @var HyperliquidTestnetExecutionAttemptRepository $repository */
        $repository = $this->entityManager->getRepository(HyperliquidTestnetExecutionAttempt::class);

        return $repository;
    }
}
