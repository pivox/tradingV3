<?php

declare(strict_types=1);

namespace App\Tests\Trading\Controller\Api;

use App\Trading\Controller\Api\EffectiveTradingConfigHistoryApiController;
use App\TradingCore\Config\Audit\EffectiveConfigDiffService;
use App\TradingCore\Config\Audit\EffectiveConfigRedactor;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRecord;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocument;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocumentFactory;
use App\TradingCore\Config\EffectiveTradingConfigReadService;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(EffectiveTradingConfigHistoryApiController::class)]
#[CoversClass(EffectiveTradingConfigReadService::class)]
final class EffectiveTradingConfigHistoryApiControllerTest extends TestCase
{
    public function testExactHistoricalLookupReturnsOnlyStoredSafeDocument(): void
    {
        $controller = $this->controller($this->registry([$this->record('a', 'c')]));

        $response = $controller->snapshot('sha256:' . str_repeat('a', 64));
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('historical_snapshot', $body['document_kind']);
        self::assertSame('***REDACTED***', $body['config']['api_secret']);
        self::assertStringNotContainsString('raw-secret', (string) $response->getContent());
    }

    public function testMalformedAndUnknownSnapshotHashesAreDistinguished(): void
    {
        $controller = $this->controller($this->registry([]));

        $malformed = $controller->snapshot('sha256:bad');
        self::assertSame(Response::HTTP_BAD_REQUEST, $malformed->getStatusCode());
        self::assertSame('invalid_config_hash', $this->json($malformed)['error']['code']);

        $unknown = $controller->snapshot('sha256:' . str_repeat('f', 64));
        self::assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());
        self::assertSame('effective_config_snapshot_not_found', $this->json($unknown)['error']['code']);
    }

    public function testConfigHashHistoryReturnsEveryProvenanceDistinctSnapshot(): void
    {
        $controller = $this->controller($this->registry([$this->record('a', 'c'), $this->record('b', 'c')]));
        $response = $controller->snapshots(new Request(['config_hash' => 'sha256:' . str_repeat('c', 64)]));
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(2, $body['count']);
        self::assertSame(
            ['sha256:' . str_repeat('a', 64), 'sha256:' . str_repeat('b', 64)],
            array_column($body['snapshots'], 'snapshot_hash'),
        );
        self::assertSame(['historical_snapshot', 'historical_snapshot'], array_column($body['snapshots'], 'document_kind'));
    }

    public function testMissingHistoryAndDiffParametersFailClosed(): void
    {
        $controller = $this->controller($this->registry([]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $controller->snapshots(new Request())->getStatusCode());
        $diff = $controller->diff(new Request(['left' => 'sha256:' . str_repeat('a', 64)]));
        self::assertSame(Response::HTTP_BAD_REQUEST, $diff->getStatusCode());
        self::assertSame(['right'], $this->json($diff)['error']['missing']);
    }

    public function testDiffLoadsExactSnapshotIdentities(): void
    {
        $controller = $this->controller($this->registry([$this->record('a', 'c', 1), $this->record('b', 'd', 2)]));
        $response = $controller->diff(new Request([
            'left' => 'sha256:' . str_repeat('a', 64),
            'right' => 'sha256:' . str_repeat('b', 64),
        ]));
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('sha256:' . str_repeat('a', 64), $body['left_snapshot_hash']);
        self::assertSame('sha256:' . str_repeat('b', 64), $body['right_snapshot_hash']);
        self::assertSame('changed', $body['changes'][0]['classification']);
    }

    private function controller(EffectiveConfigSnapshotRegistryInterface $registry): EffectiveTradingConfigHistoryApiController
    {
        $controller = new EffectiveTradingConfigHistoryApiController(new EffectiveTradingConfigReadService(
            new EffectiveTradingConfigResolver(),
            new EffectiveConfigViewerDocumentFactory(new EffectiveConfigRedactor()),
            $registry,
            new EffectiveConfigDiffService(),
        ));
        $controller->setContainer(new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException($id); }
            public function has(string $id): bool { return false; }
        });

        return $controller;
    }

    /** @param list<EffectiveConfigSnapshotRecord> $records */
    private function registry(array $records): EffectiveConfigSnapshotRegistryInterface
    {
        return new class($records) implements EffectiveConfigSnapshotRegistryInterface {
            /** @param list<EffectiveConfigSnapshotRecord> $records */
            public function __construct(private array $records) {}
            public function register(EffectiveConfigViewerDocument $document): void {}
            public function find(string $snapshotHash): ?EffectiveConfigSnapshotRecord
            {
                foreach ($this->records as $record) {
                    if (($record->document['snapshot_hash'] ?? null) === $snapshotHash) {
                        return $record;
                    }
                }
                return null;
            }
            public function findByConfigHash(string $configHash): array
            {
                return array_values(array_filter(
                    $this->records,
                    static fn (EffectiveConfigSnapshotRecord $record): bool => ($record->document['config_hash'] ?? null) === $configHash,
                ));
            }
        };
    }

    private function record(string $snapshot, string $config, int $risk = 1): EffectiveConfigSnapshotRecord
    {
        return new EffectiveConfigSnapshotRecord([
            'document_kind' => 'current_preview',
            'resolver_version' => '1.0.0',
            'validation_status' => 'valid',
            'redacted_paths' => ['config.api_secret'],
            'request' => [],
            'config' => ['api_secret' => '***REDACTED***', 'risk' => $risk],
            'config_hash' => 'sha256:' . str_repeat($config, 64),
            'condition_catalog_hash' => null,
            'ordered_layers' => [],
            'ordered_files' => [],
            'provenance' => ['risk' => ['path' => '/base.yaml']],
            'executable' => true,
            'blockers' => [],
            'snapshot_hash' => 'sha256:' . str_repeat($snapshot, 64),
        ], new \DateTimeImmutable('2026-08-20T12:00:00Z'));
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
