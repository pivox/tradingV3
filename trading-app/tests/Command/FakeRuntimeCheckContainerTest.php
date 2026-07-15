<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ExchangeRuntimeCheckCommand;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExchangeRuntimeCheckCommand::class)]
#[CoversClass(FakeExchangeStateStore::class)]
final class FakeRuntimeCheckContainerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    public function testCorruptedActiveStateIsReportedWithoutBreakingCommandConstruction(): void
    {
        $stateFile = dirname(__DIR__, 2) . '/var/fake_exchange_state.dat';
        $stateExisted = is_file($stateFile);
        $previousState = $stateExisted ? file_get_contents($stateFile) : null;
        self::assertTrue(!$stateExisted || \is_string($previousState));
        self::assertNotFalse(file_put_contents($stateFile, 'corrupted runtime-secret'));

        try {
            $application = new Application(self::bootKernel());
            $tester = new CommandTester($application->find('app:exchange:runtime-check'));

            self::assertSame(Command::SUCCESS, $tester->execute([
                'exchange' => 'fake',
                'market_type' => 'perpetual',
            ]));

            $output = $tester->getDisplay();
            self::assertStringContainsString('Readiness level: not_ready', $output);
            self::assertStringContainsString('fake_paper_runtime_check_unavailable', $output);
            self::assertStringNotContainsString('runtime-secret', $output);
            self::assertStringContainsString('Schedule ready: no', $output);
        } finally {
            self::ensureKernelShutdown();
            if ($stateExisted && \is_string($previousState)) {
                file_put_contents($stateFile, $previousState);
            } else {
                @unlink($stateFile);
            }
        }
    }

    public function testRuntimeCheckDoesNotCreateMissingActiveState(): void
    {
        $stateFile = dirname(__DIR__, 2) . '/var/fake_exchange_state.dat';
        $stateExisted = is_file($stateFile);
        $previousState = $stateExisted ? file_get_contents($stateFile) : null;
        self::assertTrue(!$stateExisted || \is_string($previousState));
        @unlink($stateFile);

        try {
            $application = new Application(self::bootKernel());
            $tester = new CommandTester($application->find('app:exchange:runtime-check'));

            self::assertSame(Command::SUCCESS, $tester->execute([
                'exchange' => 'fake',
                'market_type' => 'perpetual',
            ]));
            self::assertFileDoesNotExist($stateFile);
            self::assertStringContainsString('Schedule ready: no', $tester->getDisplay());
        } finally {
            self::ensureKernelShutdown();
            if ($stateExisted && \is_string($previousState)) {
                file_put_contents($stateFile, $previousState);
            } else {
                @unlink($stateFile);
            }
        }
    }
}
