<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BoundedDuplicateAwareJsonDecoder;
use App\Command\PaperCertificationCampaignCommand;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessExecutorInterface;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessResult;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignRunner;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignStateStore;
use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperCertificationCampaignCommand::class)]
final class PaperCertificationCampaignCommandTest extends TestCase
{
    public function testRequiresTheVersionedMatrixSpecification(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame([
            'schema_version' => PaperCertificationCampaignRunner::STATE_SCHEMA,
            'status' => 'failed',
            'blocker' => '--spec is required',
        ], json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR));
    }

    public function testRejectsDuplicateScopeMappingsBeforeCampaignExecution(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([
            '--spec' => dirname(__DIR__, 2) . '/config/trading/paper_certification/first-baseline-v1.json',
            '--configuration' => '/private/configuration.json',
            '--dataset' => ['mainnet/okx=/private/okx', 'mainnet/okx=/private/duplicate'],
            '--campaign-id' => 'first-baseline-aug23',
            '--state' => '/private/state.json',
        ]));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('paper_campaign_dataset_mapping_invalid', $payload['blocker']);
    }

    private function command(): PaperCertificationCampaignCommand
    {
        $executor = new class implements PaperCertificationCampaignProcessExecutorInterface {
            public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
            {
                throw new \LogicException('process_must_not_start');
            }
        };

        return new PaperCertificationCampaignCommand(
            new BoundedDuplicateAwareJsonDecoder(),
            new PaperCertificationMatrixBuilder(new ModeContractLoader(), new SetupContractLoader()),
            new PaperCertificationCampaignRunner(
                $executor,
                new PaperCertificationCampaignStateStore(),
                '/opt/trading-app',
                '/usr/bin/php',
            ),
        );
    }
}
