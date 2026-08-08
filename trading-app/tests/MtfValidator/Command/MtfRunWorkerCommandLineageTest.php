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
            $exit = $tester->execute([
                '--symbols' => ' ethusdt ',
                '--trade-profile' => 'scalping',
                '--exchange' => 'fake',
                '--market-type' => 'perpetual',
            ]);
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

    public function testRejectsConflictingWorkerExchangeBeforeValidator(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $identityData = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
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
            $exit = $tester->execute([
                '--symbols' => 'BTCUSDT',
                '--trade-profile' => 'scalping',
                '--exchange' => 'okx',
                '--market-type' => 'perpetual',
            ]);
        } finally {
            putenv('MTF_CANONICAL_LINEAGE');
        }

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('canonical_identity_mismatch:exchange', $tester->getDisplay());
    }
}
