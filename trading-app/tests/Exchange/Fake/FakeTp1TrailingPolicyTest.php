<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeTp1TrailingPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeTp1TrailingPolicy::class)]
final class FakeTp1TrailingPolicyTest extends TestCase
{
    public function testPolicyContractExists(): void
    {
        self::assertTrue(class_exists(FakeTp1TrailingPolicy::class));
    }

    public function testRoundTripsVersionedFixtureMetadata(): void
    {
        $policy = new FakeTp1TrailingPolicy('0.4', '100.0');

        self::assertEquals($policy, FakeTp1TrailingPolicy::fromMetadata($policy->toMetadata()));
        self::assertSame(0.4, $policy->tp1QuantityFloat());
        self::assertSame(100.0, $policy->trailingOffsetFloat());
        self::assertSame([
            FakeTp1TrailingPolicy::VERSION_KEY => FakeTp1TrailingPolicy::VERSION,
            FakeTp1TrailingPolicy::ENABLED_KEY => true,
            FakeTp1TrailingPolicy::TP1_QUANTITY_KEY => '0.4',
            FakeTp1TrailingPolicy::TRAILING_OFFSET_KEY => '100.0',
        ], $policy->toMetadata());
    }

    public function testReturnsNullOnlyWhenNoPolicyCapabilityWasRequested(): void
    {
        self::assertNull(FakeTp1TrailingPolicy::fromMetadata([
            'internal_trade_id' => 'trade-without-trailing',
        ]));
    }

    /** @param array<string,mixed> $metadata */
    #[DataProvider('invalidMetadataProvider')]
    public function testFailsExplicitlyForInvalidOrDisabledRequestedCapability(
        array $metadata,
        string $expectedMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        FakeTp1TrailingPolicy::fromMetadata($metadata);
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidMetadataProvider(): iterable
    {
        yield 'missing fields' => [[
            'fake_tp1_trailing_version' => 'fake-tp1-trailing-v1',
        ], 'fake_tp1_trailing_policy_invalid'];

        yield 'unsupported version' => [[
            'fake_tp1_trailing_version' => 'fake-tp1-trailing-v0',
            'fake_tp1_trailing_enabled' => true,
            'fake_tp1_quantity' => '0.4',
            'fake_trailing_offset' => '100.0',
        ], 'fake_tp1_trailing_policy_invalid'];

        yield 'disabled capability' => [[
            'fake_tp1_trailing_version' => 'fake-tp1-trailing-v1',
            'fake_tp1_trailing_enabled' => false,
            'fake_tp1_quantity' => '0.4',
            'fake_trailing_offset' => '100.0',
        ], 'fake_tp1_trailing_policy_disabled'];

        yield 'non decimal quantity' => [[
            'fake_tp1_trailing_version' => 'fake-tp1-trailing-v1',
            'fake_tp1_trailing_enabled' => true,
            'fake_tp1_quantity' => 'four tenths',
            'fake_trailing_offset' => '100.0',
        ], 'fake_tp1_trailing_policy_invalid'];

        yield 'zero offset' => [[
            'fake_tp1_trailing_version' => 'fake-tp1-trailing-v1',
            'fake_tp1_trailing_enabled' => true,
            'fake_tp1_quantity' => '0.4',
            'fake_trailing_offset' => '0',
        ], 'fake_tp1_trailing_policy_invalid'];
    }
}
