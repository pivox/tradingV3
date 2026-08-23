<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PaperPublicCaptureCommand;
use App\Trading\Paper\Capture\PaperPublicCaptureRunner;
use App\Trading\Paper\Capture\PaperPublicDatasetCapture;
use App\Trading\Paper\Capture\PaperPublicLiveManifestFactory;
use App\Trading\Paper\Capture\PaperPublicLiveSourceFactoryInterface;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperPublicCaptureCommand::class)]
final class PaperPublicCaptureCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/paper-public-command-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));
        $resolved = realpath($root);
        self::assertIsString($resolved);
        $this->root = $resolved;
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testEmitsOnlyCanonicalCaptureEvidenceOnSuccess(): void
    {
        $tester = new CommandTester($this->command(new CommandCaptureFactory(false)));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--venue' => 'okx',
            '--dataset-id' => 'command-okx-mainnet',
            '--duration-sec' => '300',
        ]));

        $payload = json_decode(trim($tester->getDisplay()), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('paper-public-capture-result-v1', $payload['schema_version']);
        self::assertSame('command-okx-mainnet', $payload['dataset_id']);
        self::assertSame('okx', $payload['source_venue']);
        self::assertSame('complete', $payload['state']);
        self::assertSame('not_evaluated', $payload['certification_status']);
        self::assertArrayNotHasKey('path', $payload);
        self::assertStringNotContainsString($this->root, $tester->getDisplay());
    }

    public function testRedactsEveryFailureIncludingNestedPrivateDetails(): void
    {
        $tester = new CommandTester($this->command(new CommandCaptureFactory(true)));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--venue' => 'okx',
            '--dataset-id' => 'failure-okx-mainnet',
            '--duration-sec' => '300',
        ]));

        self::assertSame([
            'blocker' => 'paper_public_capture_failed',
            'ok' => false,
            'schema_version' => 'paper-public-capture-result-v1',
        ], json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/private/capture/path', $tester->getDisplay());
        self::assertStringNotContainsString('wallet-secret', $tester->getDisplay());
    }

    public function testRejectsMissingOrNonDecimalOptionsWithoutCreatingAFactory(): void
    {
        $factory = new CommandCaptureFactory(false);
        $tester = new CommandTester($this->command($factory));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--venue' => 'okx',
            '--dataset-id' => 'invalid-duration-okx-mainnet',
            '--duration-sec' => '300.0',
        ]));
        self::assertSame(0, $factory->calls);
    }

    private function command(PaperPublicLiveSourceFactoryInterface $okx): PaperPublicCaptureCommand
    {
        $hyperliquid = new CommandCaptureFactory(false, PaperMarketDataVenue::HYPERLIQUID);

        return new PaperPublicCaptureCommand(new PaperPublicCaptureRunner(
            new PaperPublicLiveManifestFactory(),
            new PaperPublicDatasetCapture(),
            $okx,
            $hyperliquid,
            $this->root,
        ));
    }
}

final class CommandCaptureFactory implements PaperPublicLiveSourceFactoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly bool $fail,
        private readonly PaperMarketDataVenue $venue = PaperMarketDataVenue::OKX,
    ) {
    }

    public function create(string $datasetDirectory, ?LoopInterface $loop = null): PaperLiveMarketDataSourceInterface
    {
        ++$this->calls;

        return new CommandCaptureSource($this->venue, $this->fail);
    }
}

final class CommandCaptureSource implements PaperLiveMarketDataSourceInterface
{
    public function __construct(
        private readonly PaperMarketDataVenue $venue,
        private readonly bool $fail,
    ) {
    }

    public function venue(): PaperMarketDataVenue
    {
        return $this->venue;
    }

    public function events(): iterable
    {
        if ($this->fail) {
            throw new \RuntimeException('/private/capture/path wallet-secret');
        }
        yield PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            $this->venue,
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            new \DateTimeImmutable('2026-08-23T10:00:00Z'),
            new \DateTimeImmutable('2026-08-23T10:00:00.100000Z'),
            '1',
            ['price' => '65000.0', 'size' => '0.01'],
        );
    }

    public function acknowledge(string $eventId): void
    {
    }

    public function stop(): void
    {
    }

    public function isComplete(): bool
    {
        return !$this->fail;
    }

    public function requestHealthyOperatorStop(): void
    {
    }

    public function failureReason(): ?string
    {
        return $this->fail ? 'private_failure' : null;
    }
}
