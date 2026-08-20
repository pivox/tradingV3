<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeDeterministicSeed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeDeterministicSeed::class)]
final class FakeDeterministicSeedTest extends TestCase
{
    public function testSameSeedDomainAndCanonicalComponentsProduceSameIdentity(): void
    {
        $first = new FakeDeterministicSeed('golden-seed-2026-v1');
        $second = new FakeDeterministicSeed('golden-seed-2026-v1');
        $components = [
            'scenario_id' => 'private-ws-gap-v1',
            'reason' => 'fake_private_ws_sequence_gap',
            'sequence' => '3',
        ];

        self::assertSame('fake-deterministic-seed-v1', $first->schemaVersion());
        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $first->fingerprint());
        self::assertSame(
            $first->deriveHex('private-ws.resync-cycle.v1', $components),
            $second->deriveHex('private-ws.resync-cycle.v1', $components),
        );
        self::assertSame(
            '5e33d1f9500da204fd41cdb99ab73845b466d2b43c7b097cd623cc6b3f465a9e',
            $first->deriveHex('private-ws.resync-cycle.v1', $components),
            'The seed vector must remain byte-compatible with the Python runner.',
        );
    }

    public function testDifferentSeedDomainOrComponentsProduceDifferentIdentities(): void
    {
        $seed = new FakeDeterministicSeed('golden-seed-2026-v1');
        $other = new FakeDeterministicSeed('golden-seed-2026-v2');
        $components = ['scenario_id' => 'private-ws-gap-v1', 'sequence' => 3];

        $identity = $seed->deriveHex('private-ws.resync-cycle.v1', $components);

        self::assertNotSame($identity, $other->deriveHex('private-ws.resync-cycle.v1', $components));
        self::assertNotSame($identity, $seed->deriveHex('private-ws.snapshot-proof.v1', $components));
        self::assertNotSame($identity, $seed->deriveHex(
            'private-ws.resync-cycle.v1',
            ['scenario_id' => 'private-ws-gap-v1', 'sequence' => 4],
        ));
    }

    /** @return iterable<string,array{mixed}> */
    public static function invalidSeeds(): iterable
    {
        yield 'not a string' => [42];
        yield 'too short' => ['short'];
        yield 'leading whitespace' => [' golden-seed-v1'];
        yield 'unsupported character' => ['golden/seed/v1'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('invalidSeeds')]
    public function testInvalidSeedFailsClosed(mixed $seed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_deterministic_seed_invalid');

        new FakeDeterministicSeed($seed);
    }

    public function testFloatComponentsFailClosedAcrossRuntimes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_deterministic_seed_component_invalid');

        (new FakeDeterministicSeed('golden-seed-2026-v1'))->deriveHex(
            'runtime-recipe.evidence.v1',
            ['value' => 1.0e-7],
        );
    }
}
