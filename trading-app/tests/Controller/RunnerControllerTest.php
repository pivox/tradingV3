<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Runner\ExchangeStateSynchronizer;
use App\Application\Runner\OpenActivityFilter;
use App\Application\Runner\PostRunProjectionDispatcher;
use App\Application\Runner\RunResultAssembler;
use App\Application\Runner\SymbolUniverseResolver;
use App\Config\TradeEntryConfigProvider;
use App\Config\TradeEntryModeContext;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Controller\RunnerController;
use App\MtfRunner\Application\RunMtfCycleUseCase;
use App\MtfRunner\Application\Result\MtfRunResultEnricher;
use App\MtfRunner\Service\MtfRunnerService;
use App\MtfValidator\Application\TradeDecisionDispatcherInterface;
use App\MtfValidator\Repository\MtfLockRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Repository\ContractRepository;
use App\Repository\PositionRepository;
use App\Trading\Orchestration\OrchestrationContextValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[CoversClass(RunnerController::class)]
final class RunnerControllerTest extends TestCase
{
    public function testPassesConfigHashFromOrchestratorPayloadToLineageContext(): void
    {
        $validator = new class implements MtfValidatorInterface {
            public ?MtfRunRequestDto $request = null;

            public function run(MtfRunRequestDto $request): MtfRunResponseDto
            {
                $this->request = $request;

                return new MtfRunResponseDto(
                    runId: 'validator-run',
                    status: 'success',
                    executionTimeSeconds: 0.0,
                    symbolsRequested: 1,
                    symbolsProcessed: 1,
                    symbolsSuccessful: 1,
                    symbolsFailed: 0,
                    symbolsSkipped: 0,
                    successRate: 100.0,
                    results: [],
                    errors: [],
                    timestamp: new \DateTimeImmutable('2026-07-18T00:00:00+00:00'),
                );
            }

            public function getServiceName(): string
            {
                return 'runner-controller-config-hash-spy';
            }

            /** @return string[] */
            public function getListTimeframe(string $profile): array
            {
                return [];
            }
        };

        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturnMap([
            ['kernel.project_dir', '/tmp'],
            ['mode', []],
        ]);
        $controller = new RunnerController(
            new NullLogger(),
            new TradeEntryModeContext(new TradeEntryConfigProvider($parameterBag)),
            new OrchestrationContextValidator(),
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);
        $useCase = new RunMtfCycleUseCase($this->runnerService($validator));

        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'run-config-hash',
                'HTTP_X_RUN_CORRELATION_ID' => 'run-config-hash',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'set-regular',
                'HTTP_X_ORCHESTRATION_DASHBOARD_ID' => 'dashboard-fake',
                'HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v1',
            ],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'mtf_profile' => 'regular',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'open_state_snapshot' => [
                    'open_positions' => [],
                    'open_orders' => [],
                ],
                'config_hash' => 'sha256:' . str_repeat('a', 64),
            ], JSON_THROW_ON_ERROR),
        );

        $response = $controller->index($request, $useCase);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotNull($validator->request);
        self::assertSame(
            'sha256:' . str_repeat('a', 64),
            $validator->request->lineageContext->configHash,
        );
        $responseBody = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'ambiguous_calls' => 0,
                'complete' => true,
                'exchange_calls' => ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0],
                'schema_version' => 'fake-only-exchange-safety-v1',
                'source' => 'symfony_http_client_guard',
            ],
            $responseBody['data']['fake_only_safety_evidence'] ?? null,
        );
    }

    private function runnerService(MtfValidatorInterface $validator): MtfRunnerService
    {
        $logger = new NullLogger();
        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $mainProvider = $this->createMock(MainProviderInterface::class);

        return new MtfRunnerService(
            new SymbolUniverseResolver(
                $this->createMock(ContractRepository::class),
                $switchRepository,
                $logger,
                $logger,
            ),
            new OpenActivityFilter($mainProvider, $switchRepository, $logger, $logger),
            new ExchangeStateSynchronizer(
                $mainProvider,
                $this->createMock(PositionRepository::class),
                $logger,
                $logger,
            ),
            new PostRunProjectionDispatcher(
                $validator,
                $this->createMock(MessageBusInterface::class),
                $this->createMock(ClockInterface::class),
                $logger,
            ),
            new RunResultAssembler(new MtfRunResultEnricher()),
            $this->createMock(MtfLockRepository::class),
            $switchRepository,
            $validator,
            $mainProvider,
            $logger,
            $logger,
            $logger,
            $this->createMock(TradeDecisionDispatcherInterface::class),
            '/tmp',
            $this->createMock(ClockInterface::class),
        );
    }
}
