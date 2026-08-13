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

    public function testItAcceptsAtMostTwentyThousandStructuralTokens(): void
    {
        self::assertCount(20_000, $this->decoder->decode($this->wideObject(20_000)));
    }

    public function testItRejectsMoreThanTwentyThousandStructuralTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('json_structure_too_large');

        $this->decoder->decode($this->wideObject(20_001));
    }

    private function wideObject(int $members): string
    {
        $pairs = [];
        for ($index = 0; $index < $members; ++$index) {
            $pairs[] = '"k' . $index . '":0';
        }

        return '{' . implode(',', $pairs) . '}';
    }
}
