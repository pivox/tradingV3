<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperDatasetManifest::class)]
#[CoversClass(PaperDatasetVerifier::class)]
#[CoversClass(PaperMarketEvent::class)]
final class PaperFixtureContractTest extends TestCase
{
    private const MAX_FIXTURE_BYTES = 16 * 1024;

    /** @var list<string> */
    private const FORBIDDEN_FRAGMENTS = [
        'authorization',
        'api_key',
        'apikey',
        'api_secret',
        'secret_key',
        'passphrase',
        'private_key',
        'signature',
        'wallet',
        'mnemonic',
        'seed_phrase',
        'Bearer ',
        'OK-ACCESS-',
    ];

    /** @var list<string> */
    private const RAW_HEADER_KEYS = [
        'headers',
        'httpheaders',
        'requestheaders',
        'responseheaders',
        'contenttype',
        'useragent',
        'accept',
        'acceptcharset',
        'acceptencoding',
        'acceptlanguage',
        'cachecontrol',
        'connection',
        'contentencoding',
        'contentlength',
        'cookie',
        'host',
        'origin',
        'referer',
        'setcookie',
        'transferencoding',
    ];

    private const CUSTOM_HEADER_KEY_PATTERN = '/\A[xX](?:(?:[-_][A-Za-z0-9]+)+|[A-Za-z0-9]{2,})\z/D';

    private const RAW_HEADER_LINE_PATTERN = '/(?:\A|\R)(?!(?i:https?|ftp):\/\/)(?!(?i:urn|mailto):)(?![0-9]{4}-[0-9]{2}-[0-9]{2}T)[!#$%&\'*+.^_`|~0-9A-Za-z-]+:[\t ]*[^\r\n]*(?:\R|\z)/D';

    public function testCheckedInPaperMarketDataFixturesAreNormalizedVerifiedAndPublic(): void
    {
        $fixtureRoot = $this->fixtureRoot();
        $okxFixture = $fixtureRoot . '/okx-top-of-book.normalized.json';
        $hyperliquidFixture = $fixtureRoot . '/hyperliquid-top-of-book.normalized.json';
        $standaloneEvents = [$okxFixture, $hyperliquidFixture];
        $datasetDirectory = $fixtureRoot . '/complete-dataset';
        $datasetFiles = [
            $datasetDirectory . '/manifest.json',
            $datasetDirectory . '/events.ndjson',
        ];

        $expectedFiles = [...$standaloneEvents, ...$datasetFiles];
        $fixtureFiles = $this->fixtureFiles($fixtureRoot);
        sort($expectedFiles, SORT_STRING);
        self::assertSame($expectedFiles, $fixtureFiles);

        foreach ($fixtureFiles as $path) {
            self::assertFileExists($path);
            self::assertLessThan(self::MAX_FIXTURE_BYTES, filesize($path));
            $this->assertPublicFixtureContents($path, (string) file_get_contents($path));
        }

        $okxEvent = PaperMarketEvent::fromArray($this->decodeJsonFile($okxFixture));
        self::assertSame(PaperMarketDataVenue::OKX, $okxEvent->sourceVenue);
        self::assertSame('BTCUSDT', $okxEvent->symbol);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $okxEvent->channel);

        $hyperliquidEvent = PaperMarketEvent::fromArray($this->decodeJsonFile($hyperliquidFixture));
        self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $hyperliquidEvent->sourceVenue);
        self::assertSame('ETHUSDT', $hyperliquidEvent->symbol);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $hyperliquidEvent->channel);

        self::assertNotEquals($okxEvent->exchangeTimestamp, $hyperliquidEvent->exchangeTimestamp);
        self::assertNotEquals($okxEvent->receivedTimestamp, $hyperliquidEvent->receivedTimestamp);

        $events = $this->decodeNdjsonFile($datasetDirectory . '/events.ndjson');
        self::assertNotEmpty($events);
        foreach ($events as $eventData) {
            $event = PaperMarketEvent::fromArray($eventData);
            self::assertContains($event->symbol, ['BTCUSDT', 'ETHUSDT']);
            self::assertNotSame('fake', $event->sourceVenue->value);
        }

        $manifest = $this->verifyCompleteDatasetFixture($datasetDirectory);
        self::assertInstanceOf(PaperDatasetManifest::class, $manifest);
        self::assertSame(count($events), $manifest->eventCount);
        self::assertNotSame('fake', $manifest->venue->value);
        foreach (array_keys($manifest->symbols) as $symbol) {
            self::assertContains($symbol, ['BTCUSDT', 'ETHUSDT']);
        }
    }

    #[DataProvider('rawHttpHeaderProvider')]
    public function testPublicFixtureContractRejectsRawHttpHeaders(mixed $value): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertPublicValue($value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function rawHttpHeaderProvider(): iterable
    {
        yield 'request headers wrapper key' => [
            ['payload' => ['request_headers' => []]],
        ];
        yield 'lowercase compact custom header key' => [
            ['payload' => ['transport' => ['xrequestid' => 'public-fixture']]],
        ];
        yield 'generic headers key' => [
            ['payload' => ['transport' => ['headers' => []]]],
        ];
        yield 'underscore HTTP headers key' => [
            ['payload' => ['transport' => ['http_headers' => []]]],
        ];
        yield 'nested camelCase HTTP headers key' => [
            ['payload' => ['transport' => ['httpHeaders' => []]]],
        ];
        yield 'hyphen HTTP headers key' => [
            ['payload' => ['transport' => ['http-headers' => []]]],
        ];
        yield 'underscore content type key' => [
            ['payload' => ['transport' => ['content_type' => 'application/json']]],
        ];
        yield 'camelCase content type key' => [
            ['payload' => ['transport' => ['contentType' => 'application/json']]],
        ];
        yield 'hyphen content type key' => [
            ['payload' => ['transport' => ['content-type' => 'application/json']]],
        ];
        yield 'underscore user agent key' => [
            ['payload' => ['transport' => ['user_agent' => 'fixture-client']]],
        ];
        yield 'camelCase user agent key' => [
            ['payload' => ['transport' => ['userAgent' => 'fixture-client']]],
        ];
        yield 'hyphen user agent key' => [
            ['payload' => ['transport' => ['user-agent' => 'fixture-client']]],
        ];
        yield 'accept key' => [
            ['payload' => ['transport' => ['accept' => 'application/json']]],
        ];
        yield 'hyphen accept encoding key' => [
            ['payload' => ['transport' => ['accept-encoding' => 'gzip']]],
        ];
        yield 'underscore accept encoding key' => [
            ['payload' => ['transport' => ['accept_encoding' => 'gzip']]],
        ];
        yield 'camelCase accept encoding key' => [
            ['payload' => ['transport' => ['acceptEncoding' => 'gzip']]],
        ];
        yield 'cache control key' => [
            ['payload' => ['transport' => ['cache-control' => 'no-cache']]],
        ];
        yield 'hyphen custom header key' => [
            ['payload' => ['transport' => ['x-request-id' => 'public-fixture']]],
        ];
        yield 'underscore custom header key' => [
            ['payload' => ['transport' => ['x_request_id' => 'public-fixture']]],
        ];
        yield 'camelCase custom header key' => [
            ['payload' => ['transport' => ['xRequestId' => 'public-fixture']]],
        ];
        yield 'raw custom header string' => [
            ['payload' => ['metadata' => 'X-Request-ID: public-fixture']],
        ];
        yield 'raw header string without optional whitespace' => [
            ['payload' => ['metadata' => 'X-Request-ID:public-fixture']],
        ];
        yield 'raw multiline header string' => [
            ['payload' => ['metadata' => "public metadata\nContent-Type: application/json"]],
        ];
        yield 'raw digit-prefixed header string' => [
            ['payload' => ['metadata' => '123-Header:value']],
        ];
        yield 'raw punctuation-prefixed header string' => [
            ['payload' => ['metadata' => '!Header:value']],
        ];
        yield 'raw empty header string' => [
            ['payload' => ['metadata' => 'X-Empty:']],
        ];
    }

    public function testFixtureDiscoveryIncludesEverySupportedFileRecursively(): void
    {
        $temporaryRoot = tempnam(sys_get_temp_dir(), 'paper-fixture-discovery-');
        self::assertIsString($temporaryRoot);
        self::assertTrue(unlink($temporaryRoot));
        self::assertTrue(mkdir($temporaryRoot, 0700));
        self::assertTrue(mkdir($temporaryRoot . '/nested', 0700));

        try {
            self::assertNotFalse(file_put_contents($temporaryRoot . '/root.json', '{}'));
            self::assertNotFalse(file_put_contents($temporaryRoot . '/nested/events.ndjson', "{}\n"));

            self::assertSame(
                [
                    $temporaryRoot . '/nested/events.ndjson',
                    $temporaryRoot . '/root.json',
                ],
                $this->fixtureFiles($temporaryRoot),
            );
        } finally {
            @unlink($temporaryRoot . '/nested/events.ndjson');
            @unlink($temporaryRoot . '/root.json');
            @rmdir($temporaryRoot . '/nested');
            @rmdir($temporaryRoot);
        }
    }

    public function testPublicFixtureContractAllowsNonHeaderColonValues(): void
    {
        $this->assertPublicValue([
            'timestamp' => '2026-07-19T10:00:00.123456Z',
            'url' => 'https://example.test/public-market-data',
            'urn' => 'urn:example:public-market-data',
            'email' => 'mailto:paper@example.test',
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeJsonFile(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private function decodeNdjsonFile(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $events = [];
        foreach (explode("\n", rtrim($contents, "\n")) as $line) {
            self::assertNotSame('', $line);
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            self::assertIsArray($decoded);
            $events[] = $decoded;
        }

        return $events;
    }

    private function assertPublicFixtureContents(string $path, string $contents): void
    {
        $this->assertNoForbiddenValue($contents);

        if (str_ends_with($path, '.ndjson')) {
            foreach ($this->decodeNdjsonFile($path) as $event) {
                $this->assertPublicValue($event);
            }

            return;
        }

        $this->assertPublicValue($this->decodeJsonFile($path));
    }

    private function assertPublicValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $this->assertNoForbiddenValue($key);
                    self::assertNotContains(
                        str_replace(['_', '-'], '', strtolower($key)),
                        self::RAW_HEADER_KEYS,
                    );
                    self::assertDoesNotMatchRegularExpression(
                        self::CUSTOM_HEADER_KEY_PATTERN,
                        $key,
                        'Fixture contains a custom raw HTTP header key.',
                    );
                }
                $this->assertPublicValue($item);
            }

            return;
        }

        if (is_string($value)) {
            $this->assertNoForbiddenValue($value);
            self::assertDoesNotMatchRegularExpression(
                self::RAW_HEADER_LINE_PATTERN,
                $value,
                'Fixture contains a raw HTTP header-like string value.',
            );
        }
    }

    private function assertNoForbiddenValue(string $value): void
    {
        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            self::assertFalse(
                str_contains(strtolower($value), strtolower($fragment)),
                sprintf('Fixture contains forbidden public-data fragment "%s".', $fragment),
            );
        }
    }

    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/PaperMarketData';
    }

    /** @return list<string> */
    private function fixtureFiles(string $fixtureRoot): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fixtureRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function verifyCompleteDatasetFixture(string $fixtureDirectory): PaperDatasetManifest
    {
        $temporaryRoot = tempnam(sys_get_temp_dir(), 'paper-fixture-contract-');
        self::assertIsString($temporaryRoot);
        self::assertTrue(unlink($temporaryRoot));
        self::assertTrue(mkdir($temporaryRoot, 0700));
        $datasetDirectory = $temporaryRoot . '/complete-dataset';
        self::assertTrue(mkdir($datasetDirectory, 0700));

        try {
            foreach (['manifest.json', 'events.ndjson'] as $filename) {
                $target = $datasetDirectory . '/' . $filename;
                self::assertTrue(copy($fixtureDirectory . '/' . $filename, $target));
                self::assertTrue(chmod($target, 0600));
            }

            return (new PaperDatasetVerifier())->verify($datasetDirectory);
        } finally {
            foreach (['manifest.json', 'events.ndjson'] as $filename) {
                @unlink($datasetDirectory . '/' . $filename);
            }
            @rmdir($datasetDirectory);
            @rmdir($temporaryRoot);
        }
    }
}
