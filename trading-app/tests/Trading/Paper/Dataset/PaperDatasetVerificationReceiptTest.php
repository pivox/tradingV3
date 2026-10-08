<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Command\PaperDatasetReceiptCommand;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerificationReceipt;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(PaperDatasetVerificationReceipt::class)]
#[CoversClass(PaperDatasetReceiptCommand::class)]
final class PaperDatasetVerificationReceiptTest extends TestCase
{
    private const LIMIT = 1000;

    private string $root;
    private string $dataset;
    private string $receipt;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/paper-receipt-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($path, 0700));
        $this->root = (string) realpath($path);
        $recorder = new PaperDatasetRecorder($this->root . '/data', $this->manifest());
        $recorder->append($this->event('1', 1));
        $recorder->append($this->event('2', 2));
        $recorder->complete();
        $this->dataset = $recorder->datasetDirectory();
        $this->receipt = $this->root . '/receipt.json';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testIssuesAPrivateReceiptOnceForTheExactVerifiedDataset(): void
    {
        $receipts = new PaperDatasetVerificationReceipt();

        $body = $receipts->issue($this->dataset, self::LIMIT, $this->receipt);

        self::assertSame(0600, fileperms($this->receipt) & 0777);
        self::assertSame(PaperDatasetVerificationReceipt::SCHEMA_VERSION, $body['schema_version']);
        self::assertSame(PaperDatasetVerificationReceipt::codeTreeSha256(), $body['code_tree_sha256']);
        $verified = (new PaperDatasetVerifier())->verifyForBaseline($this->dataset, self::LIMIT);
        self::assertSame(
            CanonicalJson::encode($verified->toArray()),
            CanonicalJson::encode($receipts->manifestFor($this->receipt, $this->dataset, self::LIMIT)->toArray()),
        );
        $description = $receipts->describe($this->receipt, $this->dataset, self::LIMIT);
        self::assertSame('dataset-receipt-001', $description['dataset_id']);
        self::assertSame(2, $description['event_count']);
        self::assertSame($verified->eventsFileSha256, $description['events_sha256']);
        self::assertSame(self::LIMIT, $description['event_limit']);
        self::assertStringNotContainsString($this->root, CanonicalJson::encode($description));

        try {
            $receipts->issue($this->dataset, self::LIMIT, $this->receipt);
            self::fail('A receipt must never be overwritten.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_receipt_unwritable', $exception->getMessage());
        }
    }

    public function testAnyChangedEventByteFailsClosed(): void
    {
        $receipts = new PaperDatasetVerificationReceipt();
        $receipts->issue($this->dataset, self::LIMIT, $this->receipt);
        $events = (string) file_get_contents($this->dataset . '/events.ndjson');
        $handle = fopen($this->dataset . '/events.ndjson', 'r+b');
        self::assertIsResource($handle);
        $position = strpos($events, '30001');
        self::assertIsInt($position);
        fseek($handle, $position);
        fwrite($handle, '4');
        fclose($handle);

        $this->assertMismatch($receipts, 'paper_dataset_receipt_mismatch');
    }

    public function testAnIdenticalCopyReplacingTheEventsFileFailsClosed(): void
    {
        $receipts = new PaperDatasetVerificationReceipt();
        $receipts->issue($this->dataset, self::LIMIT, $this->receipt);
        $copy = $this->dataset . '/events.copy';
        self::assertTrue(copy($this->dataset . '/events.ndjson', $copy));
        chmod($copy, 0600);
        self::assertTrue(rename($copy, $this->dataset . '/events.ndjson'));

        $this->assertMismatch($receipts, 'paper_dataset_receipt_mismatch');
    }

    public function testAnotherEventLimitOrDirectorySpellingFailsClosed(): void
    {
        $receipts = new PaperDatasetVerificationReceipt();
        $receipts->issue($this->dataset, self::LIMIT, $this->receipt);

        try {
            $receipts->manifestFor($this->receipt, $this->dataset, self::LIMIT + 1);
            self::fail('Another event limit must not validate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_receipt_mismatch', $exception->getMessage());
        }
        try {
            $receipts->manifestFor($this->receipt, $this->dataset . '/', self::LIMIT);
            self::fail('A non canonical dataset path must not validate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_receipt_dataset_invalid', $exception->getMessage());
        }
    }

    public function testAnEditedOrSharedReceiptFailsClosed(): void
    {
        $receipts = new PaperDatasetVerificationReceipt();
        $receipts->issue($this->dataset, self::LIMIT, $this->receipt);
        $original = (string) file_get_contents($this->receipt);

        file_put_contents($this->receipt, str_replace('"event_limit":1000', '"event_limit":2000', $original));
        $this->assertMismatch($receipts, 'paper_dataset_receipt_invalid');

        $body = json_decode($original, true, 64, JSON_THROW_ON_ERROR);
        unset($body['receipt_sha256']);
        $body['code_tree_sha256'] = str_repeat('0', 64);
        file_put_contents($this->receipt, CanonicalJson::encode($body + ['receipt_sha256' => hash('sha256', CanonicalJson::encode($body))]) . "\n");
        $this->assertMismatch($receipts, 'paper_dataset_receipt_mismatch');

        file_put_contents($this->receipt, $original);
        chmod($this->receipt, 0644);
        $this->assertMismatch($receipts, 'paper_dataset_receipt_invalid');
    }

    public function testTheReceiptCommandVerifiesOnceAndPrintsOnlyPublicFacts(): void
    {
        $tester = new CommandTester(new PaperDatasetReceiptCommand(
            new PaperReplayReader(new PaperDatasetVerifier(), new PaperReplayCheckpointStore(), new PaperReplayClock(), self::LIMIT),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dataset' => $this->dataset, '--receipt' => $this->receipt]));
        $payload = json_decode(trim($tester->getDisplay()), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(PaperDatasetReceiptCommand::RESULT_SCHEMA, $payload['schema_version']);
        self::assertTrue($payload['issued']);
        self::assertSame(['dataset-receipt-001', 2, self::LIMIT], [$payload['dataset_id'], $payload['event_count'], $payload['event_limit']]);
        self::assertStringNotContainsString($this->root, $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['--dataset' => $this->dataset, '--receipt' => $this->receipt]));
        self::assertSame(
            ['schema_version' => PaperDatasetReceiptCommand::RESULT_SCHEMA, 'issued' => false, 'blocker' => 'paper_dataset_receipt_unwritable'],
            json_decode(trim($tester->getDisplay()), true, 16, JSON_THROW_ON_ERROR),
        );

        $command = new PaperDatasetReceiptCommand(
            new PaperReplayReader(new PaperDatasetVerifier(), new PaperReplayCheckpointStore(), new PaperReplayClock(), self::LIMIT),
        );
        self::assertSame(128 + (\defined('SIGTERM') ? \SIGTERM : 15), $command->handleSignal(\defined('SIGTERM') ? \SIGTERM : 15));
    }

    private function assertMismatch(PaperDatasetVerificationReceipt $receipts, string $expected): void
    {
        try {
            $receipts->manifestFor($this->receipt, $this->dataset, self::LIMIT);
            self::fail('The receipt must not validate.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expected, $exception->getMessage());
        }
    }

    private function manifest(): PaperDatasetManifest
    {
        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: 'dataset-receipt-001',
            venue: PaperMarketDataVenue::OKX,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT' => 'BTC-USDT-SWAP', 'ETHUSDT' => 'ETH-USDT-SWAP'],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
    }

    private function event(string $sequence, int $microseconds): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable(sprintf('2026-07-19T10:00:00.%06dZ', $microseconds)),
            receivedTimestamp: new \DateTimeImmutable(sprintf('2026-07-19T10:00:01.%06dZ', $microseconds)),
            sequence: $sequence,
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
    }
}
