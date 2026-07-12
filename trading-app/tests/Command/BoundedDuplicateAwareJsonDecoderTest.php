<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BoundedDuplicateAwareJsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoundedDuplicateAwareJsonDecoder::class)]
final class BoundedDuplicateAwareJsonDecoderTest extends TestCase
{
    public function testDecodesValidJsonWithoutChangingDecimalStrings(): void
    {
        $decoded = (new BoundedDuplicateAwareJsonDecoder())->decode(
            '{"schema_version":1,"order_plan":{"entry_price":"100.00000001"}}',
        );

        self::assertSame('100.00000001', $decoded['order_plan']['entry_price'] ?? null);
    }

    #[DataProvider('duplicateObjects')]
    public function testRejectsDuplicateObjectMembersAtEveryLevel(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BoundedDuplicateAwareJsonDecoder())->decode($json);
    }

    /** @return iterable<string, array{string}> */
    public static function duplicateObjects(): iterable
    {
        yield 'schema version' => ['{"schema_version":1,"schema_version":1,"order_plan":{}}'];
        yield 'plan exchange' => ['{"schema_version":1,"order_plan":{"exchange":"hyperliquid","exchange":"hyperliquid"}}'];
        yield 'nested stop key' => ['{"schema_version":1,"order_plan":{"protection_plan":{"stop_loss":{"stop_price":"98","stop_price":"97"}}}}'];
        yield 'escaped-equivalent key' => ['{"schema_version":1,"order_plan":{"exchange":"hyperliquid","exch\\u0061nge":"hyperliquid"}}'];
    }

    public function testRejectsExcessiveNesting(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BoundedDuplicateAwareJsonDecoder(maxDepth: 8))->decode(str_repeat('[', 9) . '0' . str_repeat(']', 9));
    }

    public function testRejectsExcessiveTokenCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BoundedDuplicateAwareJsonDecoder(maxTokens: 4))->decode('[0,1,2]');
    }
}
