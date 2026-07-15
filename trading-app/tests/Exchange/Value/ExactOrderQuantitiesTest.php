<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Value;

use App\Exchange\Value\ExactOrderQuantities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExactOrderQuantities::class)]
final class ExactOrderQuantitiesTest extends TestCase
{
    public function testPreservesAValidExactTriplet(): void
    {
        $quantities = ExactOrderQuantities::fromStrings(
            '1.123456789012345678',
            '0.400000000000000001',
            '0.723456789012345677',
        );

        self::assertSame('1.123456789012345678', $quantities->quantity);
        self::assertSame('0.400000000000000001', $quantities->filled);
        self::assertSame('0.723456789012345677', $quantities->remaining);
    }

    #[DataProvider('invalidTripletProvider')]
    public function testRejectsInvalidExactTriplets(string $quantity, string $filled, string $remaining): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExactOrderQuantities::fromStrings($quantity, $filled, $remaining);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function invalidTripletProvider(): iterable
    {
        yield 'scale 19' => ['1.0000000000000000000', '0', '1.0000000000000000000'];
        yield 'precision 37' => ['1234567890123456789.123456789012345678', '0', '1234567890123456789.123456789012345678'];
        yield 'negative' => ['1', '-0.1', '1.1'];
        yield 'zero quantity' => ['0', '0', '0'];
        yield 'sum mismatch' => ['1', '0.4', '0.7'];
    }
}
