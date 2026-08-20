<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Audit;

use App\TradingCore\Config\Audit\EffectiveConfigDiffService;
use App\TradingCore\Config\Audit\EffectiveConfigHash;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveConfigHash::class)]
#[CoversClass(EffectiveConfigDiffService::class)]
final class EffectiveConfigDiffServiceTest extends TestCase
{
    public function testItReturnsEveryClassificationInLexicalOrderAndCountsUnchanged(): void
    {
        $left = $this->record('a', [
            'changed' => 1,
            'removed' => 1,
            'same_source_changed' => '***REDACTED***',
            'unchanged' => ['nested' => true],
        ], [
            'changed' => ['path' => '/left.yaml'],
            'removed' => ['path' => '/left.yaml'],
            'same_source_changed' => ['path' => '/left.yaml'],
            'unchanged.nested' => ['path' => '/same.yaml'],
        ]);
        $right = $this->record('b', [
            'added' => 2,
            'changed' => 2,
            'same_source_changed' => '***REDACTED***',
            'unchanged' => ['nested' => true],
        ], [
            'added' => ['path' => '/right.yaml'],
            'changed' => ['path' => '/right.yaml'],
            'same_source_changed' => ['path' => '/right.yaml'],
            'unchanged.nested' => ['path' => '/same.yaml'],
        ]);

        $diff = (new EffectiveConfigDiffService())->diff($left, $right);

        self::assertSame('sha256:' . str_repeat('a', 64), $diff['left_snapshot_hash']);
        self::assertSame('sha256:' . str_repeat('b', 64), $diff['right_snapshot_hash']);
        self::assertSame(
            ['added' => 1, 'removed' => 1, 'changed' => 1, 'same_but_different_source' => 1, 'unchanged' => 1],
            $diff['summary'],
        );
        self::assertSame(
            ['added', 'changed', 'removed', 'same_source_changed'],
            array_column($diff['changes'], 'path'),
        );
        self::assertSame(
            ['added', 'changed', 'removed', 'same_but_different_source'],
            array_column($diff['changes'], 'classification'),
        );
        self::assertSame('***REDACTED***', $diff['changes'][3]['left']);
        self::assertSame('***REDACTED***', $diff['changes'][3]['right']);
        self::assertSame('/left.yaml', $diff['changes'][3]['left_source']['path']);
        self::assertSame('/right.yaml', $diff['changes'][3]['right_source']['path']);
    }

    #[DataProvider('malformedHashes')]
    public function testHashValidationRejectsNonCanonicalIdentifiers(string $hash): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('effective_config_hash_invalid');
        EffectiveConfigHash::require($hash);
    }

    /** @return iterable<string,array{string}> */
    public static function malformedHashes(): iterable
    {
        yield 'uppercase' => ['sha256:' . str_repeat('A', 64)];
        yield 'missing prefix' => [str_repeat('a', 64)];
        yield 'short' => ['sha256:abc'];
        yield 'whitespace' => [' sha256:' . str_repeat('a', 64)];
        yield 'non hex' => ['sha256:' . str_repeat('z', 64)];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $provenance
     */
    private function record(string $hashCharacter, array $config, array $provenance): EffectiveConfigSnapshotRecord
    {
        return new EffectiveConfigSnapshotRecord([
            'snapshot_hash' => 'sha256:' . str_repeat($hashCharacter, 64),
            'config_hash' => 'sha256:' . str_repeat('c', 64),
            'config' => $config,
            'provenance' => $provenance,
        ], new \DateTimeImmutable('2026-08-20T12:00:00Z'));
    }
}
