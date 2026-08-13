<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Json;

use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrictJsonObjectDecoder::class)]
final class StrictJsonObjectDecoderTest extends TestCase
{
    private StrictJsonObjectDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new StrictJsonObjectDecoder();
    }

    public function testItDecodesExactlyOneJsonObject(): void
    {
        self::assertSame(
            ['name' => 'café', 'nested' => ['enabled' => true]],
            $this->decoder->decode('{"name":"café","nested":{"enabled":true}}'),
        );
    }

    #[DataProvider('invalidPayloadProvider')]
    public function testItRejectsInvalidOrAmbiguousPayloadsWithStableReasons(
        string $payload,
        string $reason,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        $this->decoder->decode($payload);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'blank' => [" \n\t", 'input_blank'];
        yield 'malformed' => ['{"a":', 'json_invalid'];
        yield 'multiple documents' => ['{} {}', 'json_invalid'];
        yield 'trailing token' => ['{}x', 'json_invalid'];
        yield 'list root' => ['[]', 'root_object_required'];
        yield 'scalar root' => ['true', 'root_object_required'];
        yield 'duplicate root key' => ['{"a":1,"a":2}', 'duplicate_object_key'];
        yield 'duplicate nested key' => ['{"a":{"b":1,"b":2}}', 'duplicate_object_key'];
        yield 'equivalent escaped duplicate key' => ['{"a":1,"\u0061":2}', 'duplicate_object_key'];
        yield 'invalid utf8' => ["{\"a\":\"\xFF\"}", 'json_invalid'];
    }

    public function testItAcceptsInputAtExactlyEightMebibytes(): void
    {
        $prefix = '{"payload":"';
        $suffix = '"}';
        $payload = $prefix . str_repeat('x', 8_388_608 - strlen($prefix) - strlen($suffix)) . $suffix;

        $decoded = $this->decoder->decode($payload);

        self::assertSame(8_388_608 - strlen($prefix) - strlen($suffix), strlen($decoded['payload']));
    }

    public function testItRejectsInputLargerThanEightMebibytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('input_too_large');

        $this->decoder->decode(str_repeat('x', 8_388_609));
    }

    public function testItAcceptsJsonAtDepth128(): void
    {
        $payload = str_repeat('{"a":', 128) . 'null' . str_repeat('}', 128);

        self::assertIsArray($this->decoder->decode($payload));
    }

    public function testItRejectsJsonBeyondDepth128(): void
    {
        $payload = str_repeat('{"a":', 129) . 'null' . str_repeat('}', 129);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('json_depth_exceeded');

        $this->decoder->decode($payload);
    }

    public function testItAcceptsAtMostThirtyTwoThousandSevenHundredSixtyEightStructuralTokens(): void
    {
        self::assertCount(32_768, $this->decoder->decode($this->wideObject(32_768)));
    }

    public function testItRejectsMoreThanThirtyTwoThousandSevenHundredSixtyEightStructuralTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('json_structure_too_large');

        $this->decoder->decode($this->wideObject(32_769));
    }

    public function testItSupportsAnExplicitLargerTokenBoundForBoundedProtocols(): void
    {
        self::assertCount(32_769, $this->decoder->decode($this->wideObject(32_769), 32_769));
    }

    public function testItAcceptsTheLargestSupportedIndicatorProjectionRequest(): void
    {
        $payload = json_encode($this->completeSupportedIndicatorProjectionRequest(), JSON_THROW_ON_ERROR);

        self::assertSame(29_776, $this->structureTokens($payload));
        self::assertSame(
            ['1m', '5m', '15m', '1h', '4h'],
            $this->decoder->decode($payload)['requested_timeframes'],
        );
    }

    private function wideObject(int $members): string
    {
        $pairs = [];
        for ($index = 0; $index < $members; ++$index) {
            $pairs[] = '"k' . $index . '":0';
        }

        return '{' . implode(',', $pairs) . '}';
    }

    /** @return array<string, mixed> */
    private function completeSupportedIndicatorProjectionRequest(): array
    {
        $candle = [
            'schema_version' => 'backtest-candle.v1',
            'source_record_id' => str_repeat('a', 64),
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'open_at' => '2026-01-01T00:00:00.000000Z',
            'close_at' => '2026-01-01T00:01:00.000000Z',
            'available_at' => '2026-01-01T00:01:00.000000Z',
            'open' => '100',
            'high' => '101',
            'low' => '99',
            'close' => '100.5',
            'volume' => '10',
            'complete' => true,
        ];

        return [
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'indicator-projection-0001',
            'evaluated_at' => '2026-02-12T00:00:00.000000Z',
            'environment' => 'test',
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => [
                'dataset_id' => 'backtest-dataset-' . str_repeat('a', 64),
                'dataset_checksum' => 'sha256:' . str_repeat('a', 64),
                'candles_checksum' => 'sha256:' . str_repeat('b', 64),
                'quality_report_checksum' => 'sha256:' . str_repeat('c', 64),
                'source_checksum' => 'sha256:' . str_repeat('d', 64),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
            ],
            'symbol' => 'BTCUSDT',
            'requested_timeframes' => ['1m', '5m', '15m', '1h', '4h'],
            'candles_by_timeframe' => [
                '1m' => array_fill(0, 250, $candle),
                '5m' => array_fill(0, 250, $candle),
                '15m' => array_fill(0, 250, $candle),
                '1h' => array_fill(0, 1000, $candle),
            ],
        ];
    }

    private function structureTokens(string $payload): int
    {
        return substr_count($payload, '{') + substr_count($payload, '[') + substr_count($payload, ',');
    }
}
