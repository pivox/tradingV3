<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Audit;

use App\TradingCore\Config\Audit\EffectiveConfigCanonicalJson;
use App\TradingCore\Config\Audit\EffectiveConfigRedactor;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocumentFactory;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveConfigCanonicalJson::class)]
#[CoversClass(EffectiveConfigViewerDocumentFactory::class)]
final class EffectiveConfigViewerDocumentFactoryTest extends TestCase
{
    public function testItBuildsAStableSafeViewerDocumentWithoutChangingResolverHashes(): void
    {
        $snapshot = $this->snapshot([
            'schema_version' => 'effective-trading-config.v2',
            'nested' => ['apiSecret' => 'never-serialize', 'safe' => true],
        ]);
        $expectedSnapshotHash = $snapshot->toArray()['snapshot_hash'];

        $document = (new EffectiveConfigViewerDocumentFactory(new EffectiveConfigRedactor()))->fromSnapshot($snapshot);

        self::assertSame('current_preview', $document->payload['document_kind']);
        self::assertSame('1.0.0', $document->payload['resolver_version']);
        self::assertSame('valid', $document->payload['validation_status']);
        self::assertSame(['config.nested.apiSecret'], $document->payload['redacted_paths']);
        self::assertSame(EffectiveConfigRedactor::REDACTED, $document->payload['config']['nested']['apiSecret']);
        self::assertSame($expectedSnapshotHash, $document->snapshotHash());
        self::assertSame($snapshot->configHash, $document->configHash());
        self::assertSame(hash('sha256', $document->canonicalJson()), $document->redactedContentChecksum());
        self::assertSame('historical_snapshot', $document->withDocumentKind('historical_snapshot')['document_kind']);
        self::assertSame('current_preview', $document->payload['document_kind']);
    }

    public function testCanonicalJsonSortsMapsButPreservesListOrder(): void
    {
        self::assertSame(
            '{"a":{"c":3,"d":4},"list":[{"b":2,"z":1},{"a":2}],"z":1}',
            EffectiveConfigCanonicalJson::encode([
                'z' => 1,
                'list' => [['z' => 1, 'b' => 2], ['a' => 2]],
                'a' => ['d' => 4, 'c' => 3],
            ]),
        );
    }

    public function testCanonicalJsonRejectsNonFiniteNumbers(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('effective_config_document_not_canonical');

        EffectiveConfigCanonicalJson::encode(['value' => INF]);
    }

    /** @param array<string,mixed> $payload */
    private function snapshot(array $payload): EffectiveTradingConfigSnapshot
    {
        return new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest(
                'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
                'fake', 'test', 'long',
            ),
            $payload,
            'sha256:' . str_repeat('a', 64),
            'sha256:' . str_repeat('b', 64),
            [['type' => 'base', 'name' => 'base', 'path' => '/base.yaml', 'required' => true]],
            ['schema_version' => ['type' => 'base', 'name' => 'base', 'path' => '/base.yaml', 'required' => true]],
        );
    }
}
