<?php

declare(strict_types=1);

namespace App\Tests\Trading\Controller\Api;

use App\Trading\Controller\Api\EffectiveConfigUsageApiController;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRecord;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;
use App\TradingCore\Config\Audit\EffectiveConfigViewerDocument;
use App\TradingCore\Config\Usage\EffectiveConfigUsageFact;
use App\TradingCore\Config\Usage\EffectiveConfigUsageReadService;
use App\TradingCore\Config\Usage\EffectiveConfigUsageScope;
use App\TradingCore\Config\Usage\EffectiveConfigUsageStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(EffectiveConfigUsageApiController::class)]
final class EffectiveConfigUsageApiControllerTest extends TestCase
{
    public function testAllRoutesDelegateToTheirCanonicalScope(): void
    {
        $snapshot = $this->hash('a');
        $controller = $this->controller(
            [$this->fact($snapshot, $this->hash('c'))],
            [$this->record($snapshot, $this->hash('c'))],
        );

        foreach ([
            [$controller->run('run-1'), 'run', 'run-1'],
            [$controller->set('set-1'), 'set', 'set-1'],
            [$controller->decision('decision-1'), 'decision', 'decision-1'],
            [$controller->trade('trade-1'), 'trade', 'trade-1'],
        ] as [$response, $scope, $identifier]) {
            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
            self::assertSame($scope, $this->json($response)['scope']);
            self::assertSame($identifier, $this->json($response)['identifier']);
        }
    }

    public function testControllerMapsEveryStableDomainFailure(): void
    {
        $snapshot = $this->hash('a');
        $config = $this->hash('c');

        $cases = [
            [$this->controller([], []), 'run', '', 400, 'invalid_effective_config_usage_identifier'],
            [$this->controller([], []), 'run', 'missing', 404, 'effective_config_usage_not_found'],
            [$this->controller([new EffectiveConfigUsageFact('order_intent', '1', $config, null, null, null, null)], []), 'run', 'run-1', 422, 'effective_config_reference_missing'],
            [$this->controller([$this->fact($snapshot, $config)], []), 'run', 'run-1', 409, 'effective_config_snapshot_unregistered'],
            [$this->controller([$this->fact($snapshot, $this->hash('d'))], [$this->record($snapshot, $config)]), 'run', 'run-1', 409, 'effective_config_hash_conflict'],
            [$this->controller([$this->fact($snapshot, $config), $this->fact($this->hash('b'), $this->hash('d'))], [$this->record($snapshot, $config), $this->record($this->hash('b'), $this->hash('d'))]), 'trade', 'trade-1', 409, 'effective_config_usage_conflict'],
        ];

        foreach ($cases as [$controller, $method, $identifier, $status, $code]) {
            $response = $controller->{$method}($identifier);
            self::assertSame($status, $response->getStatusCode(), $code);
            self::assertSame($code, $this->json($response)['error']['code']);
            self::assertIsString($this->json($response)['error']['message']);
        }
    }

    /** @param list<EffectiveConfigUsageFact> $facts @param list<EffectiveConfigSnapshotRecord> $records */
    private function controller(array $facts, array $records): EffectiveConfigUsageApiController
    {
        $store = new class($facts) implements EffectiveConfigUsageStoreInterface {
            /** @param list<EffectiveConfigUsageFact> $facts */
            public function __construct(private readonly array $facts) {}
            public function find(EffectiveConfigUsageScope $scope, string $identifier): array { return $this->facts; }
        };
        $registry = new class($records) implements EffectiveConfigSnapshotRegistryInterface {
            /** @param list<EffectiveConfigSnapshotRecord> $records */
            public function __construct(private readonly array $records) {}
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
            public function findByConfigHash(string $configHash): array { return []; }
        };
        $controller = new EffectiveConfigUsageApiController(new EffectiveConfigUsageReadService($store, $registry));
        $controller->setContainer(new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException($id); }
            public function has(string $id): bool { return false; }
        });

        return $controller;
    }

    private function fact(string $snapshotHash, string $configHash): EffectiveConfigUsageFact
    {
        return new EffectiveConfigUsageFact(
            'order_intent',
            '1',
            $configHash,
            'effective-config-snapshot:' . $snapshotHash,
            'decision-1',
            'trade-1',
            'trade-1',
        );
    }

    private function record(string $snapshotHash, string $configHash): EffectiveConfigSnapshotRecord
    {
        return new EffectiveConfigSnapshotRecord([
            'snapshot_hash' => $snapshotHash,
            'config_hash' => $configHash,
            'config' => ['secret' => '***REDACTED***'],
        ], new \DateTimeImmutable('2026-08-20T12:00:00Z'));
    }

    private function hash(string $character): string
    {
        return 'sha256:' . str_repeat($character, 64);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
