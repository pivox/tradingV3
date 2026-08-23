<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BoundedDuplicateAwareJsonDecoder;
use App\Command\PaperCertificationMatrixCommand;
use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperCertificationMatrixCommand::class)]
final class PaperCertificationMatrixCommandTest extends TestCase
{
    public function testExportsTheVersionedRepositorySpecificationAsJson(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--spec' => dirname(__DIR__, 2) . '/config/trading/paper_certification/first-baseline-v1.json',
        ]));
        $payload = json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('paper-certification-matrix-v1', $payload['schema_version']);
        self::assertCount(12, $payload['cells']);
        self::assertSame(50, $payload['minimum_certified_trades_per_cell']);
    }

    public function testRejectsMissingOrOversizedSpecificationsWithoutLeakingContents(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--spec' => '/definitely/missing/private-spec.json']));
        self::assertSame("paper_certification_spec_unreadable\n", $tester->getDisplay());
    }

    private function command(): PaperCertificationMatrixCommand
    {
        return new PaperCertificationMatrixCommand(
            new BoundedDuplicateAwareJsonDecoder(),
            new PaperCertificationMatrixBuilder(new ModeContractLoader(), new SetupContractLoader()),
        );
    }
}
