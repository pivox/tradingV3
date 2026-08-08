<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Command;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\MtfValidator\Application\TradeDecisionDispatcherInterface;
use App\MtfValidator\Command\MtfRunWorkerCommand;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MtfRunWorkerCommand::class)]
final class MtfRunWorkerCommandLineageTest extends TestCase
{
    public function testRebuildsFullCanonicalIdentityAndBindsWorkerSymbol(): void
    {
        $captured = null;
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::once())->method('run')->willReturnCallback(
            static function (MtfRunRequestDto $request) use (&$captured): never {
                $captured = $request;
                throw new \RuntimeException('stop-after-capture');
            },
        );
        $identityData = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        unset($identityData['symbol']);
        $identityData['dry_run'] = true;
        $encoded = base64_encode(json_encode(
            $identityData,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));

        $tester = new CommandTester(new MtfRunWorkerCommand(
            $validator,
            $this->createMock(TradeDecisionDispatcherInterface::class),
        ));
        putenv('MTF_CANONICAL_LINEAGE=' . $encoded);
        try {
            $exit = $tester->execute($this->workerOptions($identityData, ' ethusdt '));
        } finally {
            putenv('MTF_CANONICAL_LINEAGE');
        }

        self::assertSame(Command::FAILURE, $exit);
        self::assertInstanceOf(MtfRunRequestDto::class, $captured);
        self::assertSame('ETHUSDT', $captured->lineageContext->symbol);
        self::assertSame($identityData['config_hash'], $captured->lineageContext->configHash);
        self::assertSame(
            $identityData['effective_config_snapshot'],
            $captured->lineageContext->effectiveConfigSnapshot?->toArray(),
        );
    }

    public function testRejectsMalformedEncodedIdentityBeforeValidator(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $tester = new CommandTester(new MtfRunWorkerCommand(
            $validator,
            $this->createMock(TradeDecisionDispatcherInterface::class),
        ));

        putenv('MTF_CANONICAL_LINEAGE=not-valid-base64!');
        try {
            $exit = $tester->execute([
                '--symbols' => 'BTCUSDT',
                '--trade-profile' => 'scalping',
            ]);
        } finally {
            putenv('MTF_CANONICAL_LINEAGE');
        }

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('canonical_identity_invalid:worker_lineage', $tester->getDisplay());
    }

    public function testRejectsModernWorkerProfileWithoutIdentity(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $tester = new CommandTester(new MtfRunWorkerCommand(
            $validator,
            $this->createMock(TradeDecisionDispatcherInterface::class),
        ));

        $exit = $tester->execute([
            '--symbols' => 'BTCUSDT',
            '--trade-profile' => 'micro_scalping',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('canonical_identity_missing:worker_lineage', $tester->getDisplay());
    }

    #[DataProvider('conflictingWorkerDuplicateProvider')]
    public function testRejectsConflictingWorkerDuplicateBeforeValidator(
        string $option,
        string $conflictingValue,
        string $field,
    ): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $identityData = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $identityData['dry_run'] = true;
        $encoded = base64_encode(json_encode(
            $identityData,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $tester = new CommandTester(new MtfRunWorkerCommand(
            $validator,
            $this->createMock(TradeDecisionDispatcherInterface::class),
        ));

        putenv('MTF_CANONICAL_LINEAGE=' . $encoded);
        try {
            $options = $this->workerOptions($identityData);
            $options[$option] = $conflictingValue;
            $exit = $tester->execute($options);
        } finally {
            putenv('MTF_CANONICAL_LINEAGE');
        }

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('canonical_identity_mismatch:' . $field, $tester->getDisplay());
    }

    #[DataProvider('missingRequiredWorkerDuplicateProvider')]
    public function testRejectsMissingRequiredModernWorkerDuplicateBeforeValidator(
        string $option,
        string $field,
    ): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $identityData = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $identityData['dry_run'] = true;
        $encoded = base64_encode(json_encode(
            $identityData,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $tester = new CommandTester(new MtfRunWorkerCommand(
            $validator,
            $this->createMock(TradeDecisionDispatcherInterface::class),
        ));

        putenv('MTF_CANONICAL_LINEAGE=' . $encoded);
        try {
            $options = $this->workerOptions($identityData);
            unset($options[$option]);
            $exit = $tester->execute($options);
        } finally {
            putenv('MTF_CANONICAL_LINEAGE');
        }

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('canonical_identity_missing:worker_' . $field, $tester->getDisplay());
    }

    public function testRejectsMissingReplayDuplicateBeforeValidator(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $identityData = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $identityData['dry_run'] = true;
        $identity = LineageContext::fromArray($identityData)->asReplay(
            'run-replay',
            'run-fixture',
            'run-fixture',
            2,
        );
        $replayData = $identity->toArray();
        $encoded = base64_encode(json_encode(
            $replayData,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $tester = new CommandTester(new MtfRunWorkerCommand(
            $validator,
            $this->createMock(TradeDecisionDispatcherInterface::class),
        ));

        putenv('MTF_CANONICAL_LINEAGE=' . $encoded);
        try {
            $options = $this->workerOptions($replayData);
            unset($options['--replay-of-run-id']);
            $exit = $tester->execute($options);
        } finally {
            putenv('MTF_CANONICAL_LINEAGE');
        }

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('canonical_identity_missing:worker_replay_of_run_id', $tester->getDisplay());
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function conflictingWorkerDuplicateProvider(): iterable
    {
        yield 'correlation run' => ['--request-id', 'run-other', 'correlation_run_id'];
        yield 'orchestration run' => ['--orchestration-run-id', 'run-other', 'orchestration_run_id'];
        yield 'set' => ['--set-id', 'set-other', 'orchestration_set_id'];
        yield 'exchange' => ['--exchange', 'okx', 'exchange'];
        yield 'market type' => ['--market-type', 'spot', 'market_type'];
        yield 'mode' => ['--trade-profile', 'day_trading', 'mode_id'];
        yield 'origin' => ['--origin', 'manual', 'origin'];
        yield 'attempt' => ['--attempt-number', '2', 'attempt_number'];
        yield 'config hash' => ['--config-hash', 'sha256:' . str_repeat('f', 64), 'config_hash'];
        yield 'dry run' => ['--dry-run', '0', 'dry_run'];
    }

    /** @return iterable<string,array{string,string}> */
    public static function missingRequiredWorkerDuplicateProvider(): iterable
    {
        yield 'exchange' => ['--exchange', 'exchange'];
        yield 'attempt' => ['--attempt-number', 'attempt_number'];
        yield 'dry run' => ['--dry-run', 'dry_run'];
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,string>
     */
    private function workerOptions(array $identity, string $symbol = 'BTCUSDT'): array
    {
        $options = [
            '--symbols' => $symbol,
            '--dry-run' => ($identity['dry_run'] ?? true) ? '1' : '0',
            '--trade-profile' => (string) $identity['mode_id'],
            '--exchange' => (string) $identity['exchange'],
            '--market-type' => (string) $identity['market_type'],
            '--request-id' => (string) $identity['correlation_run_id'],
            '--orchestration-run-id' => (string) $identity['orchestration_run_id'],
            '--set-id' => (string) $identity['orchestration_set_id'],
            '--origin' => (string) $identity['origin'],
            '--attempt-number' => (string) $identity['attempt_number'],
            '--config-hash' => (string) $identity['config_hash'],
        ];
        if (isset($identity['orchestration_dashboard_id'])) {
            $options['--dashboard-id'] = (string) $identity['orchestration_dashboard_id'];
        }
        if (isset($identity['replay_of_run_id'])) {
            $options['--replay-of-run-id'] = (string) $identity['replay_of_run_id'];
        }
        if (isset($identity['replay_of_correlation_id'])) {
            $options['--replay-of-correlation-id'] = (string) $identity['replay_of_correlation_id'];
        }

        return $options;
    }
}
