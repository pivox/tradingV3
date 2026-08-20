<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config\Audit;

use App\TradingCore\Config\Audit\EffectiveConfigRedactor;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRecord;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocument;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocumentFactory;
use App\TradingCore\Config\Audit\PersistentEffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(PersistentEffectiveTradingConfigResolver::class)]
final class PersistentEffectiveTradingConfigResolverTest extends TestCase
{
    public function testItRegistersTheExactResolvedSnapshotBeforeReturningIt(): void
    {
        $registry = $this->registry();
        $logger = $this->logger();
        $resolver = new PersistentEffectiveTradingConfigResolver(
            new EffectiveTradingConfigResolver(),
            new EffectiveConfigViewerDocumentFactory(new EffectiveConfigRedactor()),
            $registry,
            $logger,
        );

        $snapshot = $resolver->resolve($this->request());

        self::assertCount(1, $registry->registered);
        self::assertSame($snapshot->toArray()['snapshot_hash'], $registry->registered[0]->snapshotHash());
        self::assertSame($snapshot->configHash, $registry->registered[0]->configHash());
        self::assertCount(1, $logger->records);
        self::assertArrayNotHasKey('config', $logger->records[0]['context']);
        self::assertArrayNotHasKey('document', $logger->records[0]['context']);
        self::assertSame('valid', $logger->records[0]['context']['validation_status']);
    }

    public function testStorageFailureIsFailClosed(): void
    {
        $registry = new class implements EffectiveConfigSnapshotRegistryInterface {
            public function register(EffectiveConfigViewerDocument $document): void { throw new \LogicException('storage_failed'); }
            public function find(string $snapshotHash): ?EffectiveConfigSnapshotRecord { return null; }
            public function findByConfigHash(string $configHash): array { return []; }
        };
        $resolver = new PersistentEffectiveTradingConfigResolver(
            new EffectiveTradingConfigResolver(),
            new EffectiveConfigViewerDocumentFactory(new EffectiveConfigRedactor()),
            $registry,
            $this->logger(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('storage_failed');
        $resolver->resolve($this->request());
    }

    private function request(): EffectiveTradingConfigRequest
    {
        return new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        );
    }

    /** @return EffectiveConfigSnapshotRegistryInterface&object{registered:list<EffectiveConfigViewerDocument>} */
    private function registry(): EffectiveConfigSnapshotRegistryInterface
    {
        return new class implements EffectiveConfigSnapshotRegistryInterface {
            /** @var list<EffectiveConfigViewerDocument> */
            public array $registered = [];
            public function register(EffectiveConfigViewerDocument $document): void { $this->registered[] = $document; }
            public function find(string $snapshotHash): ?EffectiveConfigSnapshotRecord { return null; }
            public function findByConfigHash(string $configHash): array { return []; }
        };
    }

    /** @return AbstractLogger&object{records:list<array{level:mixed,message:string,context:array<string,mixed>}>} */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
