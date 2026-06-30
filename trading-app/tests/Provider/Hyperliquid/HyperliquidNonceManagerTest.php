<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Entity\HyperliquidNonceState;
use App\Provider\Hyperliquid\HyperliquidNonceReplayException;
use App\Provider\Hyperliquid\HyperliquidNonceScope;
use App\Provider\Hyperliquid\HyperliquidNonceScopeConflictException;
use App\Provider\Hyperliquid\PersistentHyperliquidNonceManager;
use App\Repository\HyperliquidNonceStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PersistentHyperliquidNonceManager::class)]
#[CoversClass(HyperliquidNonceScope::class)]
#[CoversClass(HyperliquidNonceState::class)]
final class HyperliquidNonceManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HyperliquidNonceStateRepository $repository;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $metadata = [$this->em->getClassMetadata(HyperliquidNonceState::class)];
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        /** @var HyperliquidNonceStateRepository $repository */
        $repository = $this->em->getRepository(HyperliquidNonceState::class);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            (new SchemaTool($this->em))->dropSchema([
                $this->em->getClassMetadata(HyperliquidNonceState::class),
            ]);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testNextNonceIsMonotonicAndSurvivesRestart(): void
    {
        $clock = new MockClock('2026-06-30 00:00:00.123 UTC');
        $scope = $this->scope();

        $manager = new PersistentHyperliquidNonceManager($this->repository, $clock);

        $first = $manager->nextNonce($scope);
        $second = $manager->nextNonce($scope);
        $afterRestart = (new PersistentHyperliquidNonceManager($this->repository, $clock))->nextNonce($scope);

        self::assertSame(1_782_777_600_123, $first);
        self::assertSame($first + 1, $second);
        self::assertSame($second + 1, $afterRestart);
        self::assertSame(1, $this->repository->count([]));
    }

    public function testScopesAreSeparatedByEnvironmentAccountAndSigner(): void
    {
        $clock = new MockClock('2026-06-30 00:00:00.500 UTC');
        $manager = new PersistentHyperliquidNonceManager($this->repository, $clock);

        $base = $manager->nextNonce($this->scope());
        $sameScopeAgain = $manager->nextNonce($this->scope());
        $otherEnvironment = $manager->nextNonce($this->scope(environment: 'testnet-alt'));
        $otherAccount = $manager->nextNonce($this->scope(
            account: '0x00000000000000000000000000000000000000aa',
            signer: '0x00000000000000000000000000000000000000ab',
        ));
        $otherSigner = $manager->nextNonce($this->scope(signer: '0x00000000000000000000000000000000000000bb'));

        self::assertSame(1_782_777_600_500, $base);
        self::assertSame($base + 1, $sameScopeAgain);
        self::assertSame($base, $otherEnvironment);
        self::assertSame($base, $otherAccount);
        self::assertSame($base, $otherSigner);
        self::assertSame(4, $this->repository->count([]));
    }

    public function testSameSignerCannotBeReusedAcrossAccounts(): void
    {
        $clock = new MockClock('2026-06-30 00:00:00.700 UTC');
        $manager = new PersistentHyperliquidNonceManager($this->repository, $clock);
        $scope = $this->scope();

        $first = $manager->nextNonce($scope);

        $this->expectException(HyperliquidNonceScopeConflictException::class);
        $this->expectExceptionMessage('hyperliquid_nonce_scope_conflict');

        try {
            $manager->nextNonce($this->scope(account: '0x00000000000000000000000000000000000000aa'));
        } finally {
            self::assertSame($first + 1, $manager->nextNonce($scope));
        }
    }

    public function testObservedNonceReplayIsRejectedAndHigherObservedNonceAdvancesState(): void
    {
        $clock = new MockClock('2026-06-30 00:00:00.000 UTC');
        $manager = new PersistentHyperliquidNonceManager($this->repository, $clock);
        $scope = $this->scope();

        $first = $manager->nextNonce($scope);

        try {
            $manager->recordObservedNonce($scope, $first);
            self::fail('Expected replayed nonce to be rejected.');
        } catch (HyperliquidNonceReplayException $exception) {
            self::assertSame('hyperliquid_nonce_replay_detected', $exception->getMessage());
        }

        $manager->recordObservedNonce($scope, $first + 100);

        self::assertSame($first + 101, $manager->nextNonce($scope));
    }

    public function testIndependentManagersReserveDistinctNoncesOnSameStorage(): void
    {
        $clock = new MockClock('2026-06-30 00:00:00.000 UTC');
        $scope = $this->scope();

        $firstManager = new PersistentHyperliquidNonceManager($this->repository, $clock);
        $secondManager = new PersistentHyperliquidNonceManager($this->repository, $clock);

        self::assertSame(1_782_777_600_000, $firstManager->nextNonce($scope));
        self::assertSame(1_782_777_600_001, $secondManager->nextNonce($scope));
    }

    private function scope(
        string $environment = 'testnet',
        string $network = 'testnet',
        string $account = '0x0000000000000000000000000000000000000001',
        string $signer = '0x0000000000000000000000000000000000000002',
    ): HyperliquidNonceScope {
        return new HyperliquidNonceScope(
            environment: $environment,
            network: $network,
            accountAddress: $account,
            signerAddress: $signer,
        );
    }
}
