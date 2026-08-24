<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PaperExecutionReplayCommand;
use App\Command\PaperReplayRuntimeCheckCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PaperExecutionReplayCommand::class)]
#[CoversClass(PaperReplayRuntimeCheckCommand::class)]
final class PaperOperatorCommandContainerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    /** @return iterable<string, array{string}> */
    public static function paperCommands(): iterable
    {
        yield 'runtime check' => ['app:paper-market:runtime-check'];
        yield 'replay' => ['app:paper-market:replay'];
    }

    #[DataProvider('paperCommands')]
    public function testPaperStrategyProfileCoexistsWithSymfonyGlobalProfile(
        string $commandName,
    ): void {
        try {
            $application = new Application(self::bootKernel());
            $command = $application->find($commandName);

            $command->mergeApplicationDefinition();

            self::assertTrue($command->getDefinition()->hasOption('profile'));
            self::assertTrue($command->getDefinition()->hasOption('strategy-profile'));
        } finally {
            self::ensureKernelShutdown();
        }
    }
}
