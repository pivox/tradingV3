<?php

declare(strict_types=1);

namespace App\Tests\Trading\Controller\Api;

use App\Trading\Controller\Api\PositionAnalysisApiController;
use App\Trading\Service\PositionTradeAnalysisReaderInterface;
use App\Trading\Service\RunTradeOutcomeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OBS-003 — Endpoint `GET /api/positions/analysis` : run_id requis (400), agrégat
 * explicite même vide (200), indisponibilité de la source NON masquée (503,
 * `source_available = false`), jamais de 500.
 */
#[CoversClass(PositionAnalysisApiController::class)]
final class PositionAnalysisApiControllerTest extends TestCase
{
    public function testMissingRunIdReturns400(): void
    {
        $controller = $this->controller($this->reader([]));
        $response = $controller->analysis(new Request());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('missing_run_id', $this->json($response)['error']);
    }

    public function testKnownRunReturns200WithAggregate(): void
    {
        $controller = $this->controller($this->reader([]));
        $response = $controller->analysis(new Request(['run_id' => 'run_dashA']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('run_dashA', $body['run_id']);
        self::assertSame('run_dashA', $body['correlation_run_id']);
        self::assertTrue($body['source_available']);
        self::assertSame(0, $body['summary']['trade_count']);
    }

    public function testSourceUnavailableReturns503AndFlagsIt(): void
    {
        $reader = new class implements PositionTradeAnalysisReaderInterface {
            public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array
            {
                throw new \RuntimeException('view unavailable');
            }
        };

        $controller = $this->controller($reader);
        $response = $controller->analysis(new Request(['run_id' => 'run_dashA']));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['source_available']);
        self::assertSame('source_unavailable', $body['error']);
        // l'indisponibilité ne doit jamais ressembler à "0 trade"
        self::assertArrayNotHasKey('summary', $body);
    }

    public function testLongRunIdIsHashedInCorrelation(): void
    {
        $controller = $this->controller($this->reader([]));
        $long = str_repeat('a', 65);
        $response = $controller->analysis(new Request(['run_id' => $long]));

        $body = $this->json($response);
        self::assertSame(64, strlen($body['correlation_run_id']));
        self::assertNotSame(substr($long, 0, 64), $body['correlation_run_id']);
    }

    private function controller(PositionTradeAnalysisReaderInterface $reader): PositionAnalysisApiController
    {
        $controller = new PositionAnalysisApiController(new RunTradeOutcomeService($reader));

        // Container minimal : AbstractController::json() teste `has('serializer')`.
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not available: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * @return array<string,mixed>
     */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function reader(array $rows): PositionTradeAnalysisReaderInterface
    {
        return new class($rows) implements PositionTradeAnalysisReaderInterface {
            public function __construct(private readonly array $rows)
            {
            }

            public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array
            {
                return $this->rows;
            }
        };
    }
}
