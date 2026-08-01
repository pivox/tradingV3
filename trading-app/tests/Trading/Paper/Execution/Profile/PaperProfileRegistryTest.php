<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Profile;

use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperProfileEligibility::class)]
#[CoversClass(PaperProfileRegistry::class)]
final class PaperProfileRegistryTest extends TestCase
{
    #[DataProvider('legacyProfileProvider')]
    public function testCurrentProfilesAreReferenceOnly(string $profile): void
    {
        self::assertSame(
            PaperProfileEligibility::REFERENCE_ONLY,
            (new PaperProfileRegistry())->require($profile),
        );
    }

    public static function legacyProfileProvider(): iterable
    {
        yield ['regular'];
        yield ['scalper'];
        yield ['scalper_micro'];
    }

    #[DataProvider('invalidProfileProvider')]
    public function testAliasesDefaultsAndUnknownProfilesAreRejected(string $profile): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_strategy_profile_unknown');

        (new PaperProfileRegistry())->require($profile);
    }

    public static function invalidProfileProvider(): iterable
    {
        yield 'blank' => [''];
        yield 'implicit default' => ['default'];
        yield 'case alias' => ['REGULAR'];
        yield 'trim alias' => [' regular '];
        yield 'future profile' => ['crash_short'];
    }
}
