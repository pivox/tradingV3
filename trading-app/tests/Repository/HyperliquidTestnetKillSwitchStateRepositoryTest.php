<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\HyperliquidTestnetKillSwitchState;
use App\Repository\HyperliquidTestnetKillSwitchStateRepository;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(HyperliquidTestnetKillSwitchState::class)]
#[CoversClass(HyperliquidTestnetKillSwitchStateRepository::class)]
final class HyperliquidTestnetKillSwitchStateRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private HyperliquidTestnetKillSwitchStateRepository $repository;

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

        $metadata = [$entityManager->getClassMetadata(HyperliquidTestnetKillSwitchState::class)];
        $tool = new SchemaTool($entityManager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->repository = $this->newRepository();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            (new SchemaTool($this->entityManager))->dropSchema([
                $this->entityManager->getClassMetadata(HyperliquidTestnetKillSwitchState::class),
            ]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testDefaultsToNotTrippedAndImplementsTripContract(): void
    {
        self::assertInstanceOf(HyperliquidKillSwitchTripInterface::class, $this->repository);
        self::assertFalse($this->repository->isTripped());
    }

    public function testTripPersistsAcrossEntityManagerClearAndRepositoryRestart(): void
    {
        $this->repository->trip('hyperliquid_compensation_unconfirmed', ['correlation_id' => 'corr-1']);

        $this->entityManager->clear();
        $restarted = $this->newRepository();

        self::assertTrue($restarted->isTripped());
        self::assertSame('hyperliquid_compensation_unconfirmed', $restarted->currentReason());
        self::assertSame(['correlation_id' => 'corr-1'], $restarted->currentAuditContext());
    }

    public function testRepeatedTripPreservesFirstCauseAndContext(): void
    {
        $this->repository->trip('first_cause', ['correlation_id' => 'corr-first']);
        $this->repository->trip('second_cause', ['correlation_id' => 'corr-second']);

        self::assertTrue($this->repository->isTripped());
        self::assertSame('first_cause', $this->repository->currentReason());
        self::assertSame(['correlation_id' => 'corr-first'], $this->repository->currentAuditContext());
        self::assertSame(1, $this->repository->count([]));
    }

    public function testTripRedactsSecretsAndRawPayloadsAndBoundsPersistedFields(): void
    {
        $secret = 'never-persist-this-secret';
        $this->repository->trip(
            'bad reason with secret=' . $secret,
            [
                'correlation_id' => str_repeat('c', 500),
                'private_key' => $secret,
                'nested' => ['authorization' => $secret, 'safe' => str_repeat('s', 500)],
                'raw_payload' => ['secret' => $secret],
            ],
        );

        $encoded = json_encode([
            'reason' => $this->repository->currentReason(),
            'context' => $this->repository->currentAuditContext(),
        ], JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($secret, $encoded);
        self::assertStringNotContainsString('raw_payload', $encoded);
        self::assertLessThanOrEqual(4_096, strlen($encoded));
        self::assertSame(128, strlen((string) $this->repository->currentAuditContext()['correlation_id']));
    }

    public function testTripRedactsSensitiveValuesStoredUnderBenignKeys(): void
    {
        $privateKey = '0x' . str_repeat('a', 64);
        $bearer = 'Bearer header.payload.signature';
        $assignment = 'token=plain-text-sensitive-value';
        $bypassAssignments = [
            'first' => 'api_key=api-secret',
            'second' => 'passphrase=wallet-passphrase',
            'third' => 'cookie=session-cookie',
            'fourth' => 'signature=raw-signature',
            'fifth' => 'credentials=credential-bundle',
            'sixth' => 'memo=sensitive-memo',
        ];
        $this->repository->trip('compensation_unconfirmed', [
            'note' => $privateKey,
            'detail' => $bearer,
            'message' => $assignment,
            'nested' => ['description' => 'Authorization: ' . $bearer],
            'diagnostics' => $bypassAssignments,
            'correlation_id' => 'corr-safe',
        ]);

        $encoded = json_encode($this->repository->currentAuditContext(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($privateKey, $encoded);
        self::assertStringNotContainsString($bearer, $encoded);
        self::assertStringNotContainsString($assignment, $encoded);
        foreach ($bypassAssignments as $bypassAssignment) {
            self::assertStringNotContainsString($bypassAssignment, $encoded);
        }
        self::assertSame('corr-safe', $this->repository->currentAuditContext()['correlation_id']);
    }

    private function newRepository(): HyperliquidTestnetKillSwitchStateRepository
    {
        /** @var HyperliquidTestnetKillSwitchStateRepository $repository */
        $repository = $this->entityManager->getRepository(HyperliquidTestnetKillSwitchState::class);

        return $repository;
    }
}
