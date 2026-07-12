<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HyperliquidTestnetQuarantineRecoverCommand;
use App\TradingCore\Execution\Hyperliquid\HyperliquidDurableTripPersistenceException;
use App\TradingCore\Execution\Hyperliquid\HyperliquidQuarantineRecoveryInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidQuarantineRecoveryStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HyperliquidTestnetQuarantineRecoverCommand::class)]
final class HyperliquidTestnetQuarantineRecoverCommandTest extends TestCase
{
    private const CONFIRMATION = 'CONFIRM_HYPERLIQUID_TESTNET_QUARANTINE_TRANSFER';

    public function testWrongConfirmationRefusesBeforeRecoveryCall(): void
    {
        $recovery = new StubQuarantineRecovery(HyperliquidQuarantineRecoveryStatus::Transferred);
        $tester = new CommandTester(new HyperliquidTestnetQuarantineRecoverCommand($recovery));

        $exitCode = $tester->execute(['--confirm' => 'yes']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $recovery->calls);
        self::assertStringContainsString('Quarantine recovery confirmation rejected.', $tester->getDisplay());
    }

    #[DataProvider('recoveryOutcomes')]
    public function testPrintsOnlyFixedStatusForRecoveryOutcome(
        HyperliquidQuarantineRecoveryStatus $status,
        int $expectedExitCode,
        string $expectedMessage,
    ): void {
        $recovery = new StubQuarantineRecovery($status);
        $tester = new CommandTester(new HyperliquidTestnetQuarantineRecoverCommand($recovery));

        $exitCode = $tester->execute(['--confirm' => self::CONFIRMATION]);

        self::assertSame($expectedExitCode, $exitCode);
        self::assertSame(1, $recovery->calls);
        self::assertSame($expectedMessage . "\n", $tester->getDisplay());
    }

    /** @return iterable<string, array{HyperliquidQuarantineRecoveryStatus,int,string}> */
    public static function recoveryOutcomes(): iterable
    {
        yield 'safe transfer' => [
            HyperliquidQuarantineRecoveryStatus::Transferred,
            Command::SUCCESS,
            'Fallback quarantine transferred to the already-tripped database kill switch.',
        ];
        yield 'no marker' => [
            HyperliquidQuarantineRecoveryStatus::NoMarker,
            Command::SUCCESS,
            'No fallback quarantine marker is present.',
        ];
        yield 'repository not tripped' => [
            HyperliquidQuarantineRecoveryStatus::RepositoryNotTripped,
            Command::FAILURE,
            'Quarantine recovery refused: database kill switch is not tripped.',
        ];
    }

    public function testUnreadableRepositoryOrDurabilityFailureUsesFixedFailureMessage(): void
    {
        $recovery = new StubQuarantineRecovery(exception: new HyperliquidDurableTripPersistenceException());
        $tester = new CommandTester(new HyperliquidTestnetQuarantineRecoverCommand($recovery));

        $exitCode = $tester->execute(['--confirm' => self::CONFIRMATION]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(1, $recovery->calls);
        self::assertSame(
            "Quarantine recovery refused: durable quarantine state is unreadable.\n",
            $tester->getDisplay(),
        );
    }

    public function testHelpStatesOneWayTransferAndRetainedLockRestartRequirement(): void
    {
        $command = new HyperliquidTestnetQuarantineRecoverCommand(
            new StubQuarantineRecovery(HyperliquidQuarantineRecoveryStatus::NoMarker),
        );

        self::assertStringContainsString('already-tripped database kill switch', $command->getHelp());
        self::assertStringContainsString('Worker restart is required for retained-lock recovery.', $command->getHelp());
        self::assertStringContainsString(self::CONFIRMATION, $command->getHelp());
    }
}

final class StubQuarantineRecovery implements HyperliquidQuarantineRecoveryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?HyperliquidQuarantineRecoveryStatus $status = null,
        private readonly ?\Throwable $exception = null,
    ) {
    }

    public function recoverFallbackMarker(): HyperliquidQuarantineRecoveryStatus
    {
        ++$this->calls;
        if ($this->exception instanceof \Throwable) {
            throw $this->exception;
        }

        return $this->status ?? HyperliquidQuarantineRecoveryStatus::NoMarker;
    }
}
