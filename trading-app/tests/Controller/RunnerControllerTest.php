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
use App\Contract\Runtime\AuditLoggerInterface;
use App\Controller\RunnerController;
use App\MtfRunner\Application\RunMtfCycleUseCase;
use App\MtfRunner\Application\Result\MtfRunResultEnricher;
use App\MtfRunner\Service\MtfRunnerService;
use App\MtfValidator\Application\TradeDecisionDispatcherInterface;
use App\MtfValidator\Policy\CanonicalMtfPolicyPreflight;
use App\MtfValidator\Repository\MtfLockRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Repository\ContractRepository;
use App\Provider\Context\ExchangeContext;
use App\Repository\PositionRepository;
use App\Trading\Orchestration\OrchestrationContextValidator;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Tests\Trading\Lineage\CanonicalSnapshotMetadataFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[CoversClass(RunnerController::class)]
final class RunnerControllerTest extends TestCase
{
    public function testProfilelessLegacyRequestUsesFirstEnabledModeForResolutionAndValidation(): void
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
                return 'runner-controller-legacy-default-spy';
            }

            /** @return string[] */
            public function getListTimeframe(string $profile): array
            {
                return [];
            }
        };
        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository->expects(self::once())
            ->method('allActiveSymbolNames')
            ->with([], false, 'scalper', self::isInstanceOf(ExchangeContext::class))
            ->willReturn(['BTCUSDT']);

        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes())->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator, contractRepository: $contractRepository)),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotNull($validator->request);
        self::assertSame('scalper', $validator->request->profile);
        self::assertSame('scalper', $validator->request->lineageContext->mtfProfile);
    }

    public function testExplicitLegacyMtfProfileWinsOverEnabledModeDefault(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::once())
            ->method('run')
            ->with(self::callback(static fn(MtfRunRequestDto $request): bool => $request->profile === 'regular'))
            ->willReturn($this->successfulMtfResponse());
        $validator->method('getListTimeframe')->willReturn([]);

        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
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
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes())->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator)),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testGenericLegacyModeActsAsExplicitProfile(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::once())
            ->method('run')
            ->with(self::callback(static fn(MtfRunRequestDto $request): bool => $request->profile === 'regular'))
            ->willReturn($this->successfulMtfResponse());
        $validator->method('getListTimeframe')->willReturn([]);

        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'mode' => 'regular',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes())->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator)),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testStrictGenericModeUsesEnabledProfileDefaultAndRemainsValidationMode(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::once())
            ->method('run')
            ->with(self::callback(static fn(MtfRunRequestDto $request): bool => (
                $request->profile === 'scalper' && $request->mode === 'strict'
            )))
            ->willReturn($this->successfulMtfResponse());
        $validator->method('getListTimeframe')->willReturn([]);

        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'mode' => 'strict',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes())->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator)),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCanonicalModeIdIsNotReplacedByLegacyEnabledModeDefault(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                '[Runner Controller] Resolved request profile',
                self::callback(static fn(array $context): bool => (
                    ($context['profile'] ?? null) === 'scalping'
                    && ($context['contract_kind'] ?? null) === 'modern'
                )),
            );
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'run-canonical',
                'HTTP_X_RUN_CORRELATION_ID' => 'run-canonical',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'set-canonical',
            ],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'trading_identity' => self::canonicalTradingIdentity(),
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes(), $logger)->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator)),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('rejected', $body['data']['run']['status'] ?? null);
        self::assertSame('scalping', $body['data']['run']['lineage']['mode_id'] ?? null);
    }

    public function testCanonicalActiveUniverseRequestReachesPolicyPreflightWithoutProviderWork(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method(self::anything());
        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository->expects(self::never())->method(self::anything());
        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->expects(self::never())->method(self::anything());
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'run-canonical',
                'HTTP_X_RUN_CORRELATION_ID' => 'run-canonical',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'set-canonical',
            ],
            content: json_encode([
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'trading_identity' => self::canonicalTradingIdentity(),
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes())->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService(
                $validator,
                contractRepository: $contractRepository,
                mainProvider: $mainProvider,
            )),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('rejected', $body['data']['run']['status'] ?? null);
        self::assertSame('canonical_config_invalid:roots', $body['data']['run']['reason'] ?? null);
        self::assertArrayNotHasKey('symbol', $body['data']['run']['lineage'] ?? []);
    }

    public function testLowercaseCanonicalSymbolReachesPolicyPreflightWithoutProviderWork(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method(self::anything());
        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository->expects(self::never())->method(self::anything());
        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->expects(self::never())->method(self::anything());
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'run-canonical',
                'HTTP_X_RUN_CORRELATION_ID' => 'run-canonical',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'set-canonical',
            ],
            content: json_encode([
                'symbols' => ['  btcusdt  '],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'trading_identity' => self::canonicalTradingIdentity(),
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes())->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService(
                $validator,
                contractRepository: $contractRepository,
                mainProvider: $mainProvider,
            )),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('rejected', $body['data']['run']['status'] ?? null);
        self::assertSame('canonical_config_invalid:roots', $body['data']['run']['reason'] ?? null);
        self::assertNull($body['data']['run']['lineage']['symbol'] ?? null);
    }

    /** @param array<string,mixed> $nestedFields */
    #[\PHPUnit\Framework\Attributes\DataProvider('serverOwnedNestedIdentityFields')]
    public function testRejectsServerOwnedNestedIdentityWithoutLineageOrAuditWork(
        array $nestedFields,
        string $expectedErrorCode,
        string $sensitiveValue,
    ): void {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method(self::anything());
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method(self::anything());
        $tradingIdentity = self::canonicalTradingIdentity();
        foreach ($nestedFields as $field => $value) {
            $tradingIdentity[$field] = $value;
        }
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'server-run',
                'HTTP_X_RUN_CORRELATION_ID' => 'server-run',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'server-set',
            ],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'trading_identity' => $tradingIdentity,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller()->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator, auditLogger: $auditLogger)),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame($expectedErrorCode, $body['error_code'] ?? null);
        self::assertSame('Canonical trading identity rejected.', $body['message'] ?? null);
        self::assertStringNotContainsString($sensitiveValue, (string) $response->getContent());
        self::assertArrayNotHasKey('data', $body);
    }

    /** @return iterable<string, array{array<string,mixed>, string, string}> */
    public static function serverOwnedNestedIdentityFields(): iterable
    {
        yield 'run id' => [
            ['orchestration_run_id' => 'sensitive-attacker-run'],
            'canonical_identity_forbidden:orchestration_run_id',
            'sensitive-attacker-run',
        ];
        yield 'set id' => [
            ['set_id' => 'sensitive-attacker-set'],
            'canonical_identity_forbidden:set_id',
            'sensitive-attacker-set',
        ];
        yield 'exchange and symbol are sorted deterministically' => [
            ['symbol' => 'SENSITIVEATTACKERSYMBOL', 'exchange' => 'sensitive-attacker-exchange'],
            'canonical_identity_forbidden:exchange',
            'sensitive-attacker-exchange',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedExplicitTradingIdentities')]
    public function testRejectsMalformedExplicitTradingIdentityBeforePeripheralWork(
        mixed $tradingIdentity,
        string $expectedErrorCode,
        string $sensitiveValue,
    ): void {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method(self::anything());
        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository->expects(self::never())->method(self::anything());
        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->expects(self::never())->method(self::anything());
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method(self::anything());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('debug');
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'server-run',
                'HTTP_X_RUN_CORRELATION_ID' => 'server-run',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'server-set',
            ],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => false,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'trading_identity' => $tradingIdentity,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller($this->enabledModes(), $logger)->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService(
                $validator,
                contractRepository: $contractRepository,
                mainProvider: $mainProvider,
                auditLogger: $auditLogger,
            )),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame($expectedErrorCode, $body['error_code'] ?? null);
        self::assertSame('Canonical trading identity rejected.', $body['message'] ?? null);
        self::assertStringNotContainsString($sensitiveValue, (string) $response->getContent());
        self::assertArrayNotHasKey('data', $body);
    }

    /** @return iterable<string, array{mixed, string, string}> */
    public static function malformedExplicitTradingIdentities(): iterable
    {
        yield 'non-array string' => [
            'sensitive-non-array-identity',
            'canonical_identity_invalid:trading_identity',
            'sensitive-non-array-identity',
        ];
        yield 'empty object' => [
            (object) [],
            'canonical_identity_invalid:trading_identity',
            'server-run',
        ];
        yield 'mode only' => [
            ['mode_id' => 'scalping'],
            'canonical_identity_missing:setup_id',
            'scalping',
        ];
        yield 'setup only' => [
            ['setup_id' => 'scalping.pullback.long'],
            'canonical_identity_missing:mode_id',
            'scalping.pullback.long',
        ];
    }

    /**
     * @param array<string, mixed> $tradingIdentity
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCanonicalIdentityPayloads')]
    public function testCanonicalIdentityFailuresReturnStableSanitized422(
        array $tradingIdentity,
        string $expectedErrorCode,
        string $sensitiveInput,
    ): void {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'run-canonical',
                'HTTP_X_RUN_CORRELATION_ID' => 'run-canonical',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'set-canonical',
            ],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'trading_identity' => $tradingIdentity,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller()->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator)),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame($expectedErrorCode, $body['error_code'] ?? null);
        self::assertSame('Canonical trading identity rejected.', $body['message'] ?? null);
        self::assertStringNotContainsString($sensitiveInput, (string) $response->getContent());
    }

    /**
     * @param object $ambiguousValue
     * @param list<string> $collidingList
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jsonObjectsThatCollapseDuringAssociativeDecode')]
    public function testCanonicalIdentityRejectsJsonObjectsThatCollapseDuringAssociativeDecode(
        object $ambiguousValue,
        array $collidingList,
    ): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $identity = self::canonicalTradingIdentity();
        $catalogHash = (string) $identity['condition_catalog_hash'];
        $listConfig = ['ambiguous' => $collidingList];
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($listConfig, $catalogHash);
        $identity['config_hash'] = $configHash;
        $identity['effective_config_snapshot']['config_hash'] = $configHash;
        $identity['effective_config_snapshot']['config'] = (object) [
            'ambiguous' => $ambiguousValue,
        ];
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RUN_ID' => 'run-canonical',
                'HTTP_X_RUN_CORRELATION_ID' => 'run-canonical',
                'HTTP_X_ORCHESTRATION_SET_ID' => 'set-canonical',
            ],
            content: json_encode([
                'symbols' => ['BTCUSDT'],
                'dry_run' => true,
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'workers' => 1,
                'sync_tables' => false,
                'process_tp_sl' => false,
                'skip_open_state_filter' => true,
                'trading_identity' => $identity,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller()->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator)),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('canonical_identity_invalid:effective_config_structure', $body['error_code'] ?? null);
    }

    /** @return iterable<string,array{object,list<string>}> */
    public static function jsonObjectsThatCollapseDuringAssociativeDecode(): iterable
    {
        yield 'ordered numeric object' => [(object) ['0' => 'a', '1' => 'b'], ['a', 'b']];
        yield 'empty object' => [(object) [], []];
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidCanonicalIdentityPayloads(): iterable
    {
        $base = self::canonicalTradingIdentity();
        $missing = $base;
        unset($missing['setup_version']);
        yield 'missing field' => [$missing, 'canonical_identity_missing:setup_version', 'scalping.pullback.long'];

        $malformed = $base;
        $malformed['config_hash'] = 'sensitive-invalid-hash';
        yield 'malformed field' => [$malformed, 'canonical_identity_invalid:config_hash', 'sensitive-invalid-hash'];

        $mismatch = $base;
        $mismatch['side'] = 'SHORT';
        yield 'semantic mismatch' => [$mismatch, 'canonical_identity_mismatch:side', 'SHORT'];
    }

    public function testUnexpectedFailureReturnsSanitized500DistinctFromCanonicalInputErrors(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->method('getListTimeframe')->willReturn([]);
        $validator->expects(self::once())->method('run')->willThrowException(
            new \RuntimeException('sensitive-internal-detail'),
        );
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
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
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller()->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator)),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('internal_error', $body['error_code'] ?? null);
        self::assertSame('Unable to run MTF cycle.', $body['message'] ?? null);
        self::assertStringNotContainsString('sensitive-internal-detail', (string) $response->getContent());
    }

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

        $controller = new RunnerController(
            new NullLogger(),
            $this->modeContext(),
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
                'HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v2',
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
                'async_exchange_capable_dispatches_suppressed' => true,
                'complete' => true,
                'exchange_call_proof' => [
                    'bitmart' => 'fake_provider_boundary',
                    'hyperliquid' => 'http_client_guard',
                    'okx' => 'http_client_guard',
                ],
                'exchange_calls' => ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0],
                'schema_version' => 'fake-only-exchange-safety-v2',
                'source' => 'symfony_fake_provider_boundary_and_http_guards',
            ],
            $responseBody['data']['fake_only_safety_evidence'] ?? null,
        );
    }

    public function testIgnoresClientAsyncSuppressionWithoutSafetyEvidenceHeader(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::once())
            ->method('run')
            ->willReturn($this->successfulMtfResponse());
        $validator->expects(self::once())
            ->method('getListTimeframe')
            ->with('regular')
            ->willReturn([]);
        $tradeDecisionDispatcher = $this->createMock(TradeDecisionDispatcherInterface::class);
        $tradeDecisionDispatcher->expects(self::once())->method('dispatchFromResponse');

        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
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
                'suppress_exchange_capable_async_work' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller()->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator, $tradeDecisionDispatcher)),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsArray($body);
        self::assertIsArray($body['data'] ?? null);
        self::assertArrayNotHasKey('fake_only_safety_evidence', $body['data']);
    }

    public function testSafetyEvidenceHeaderOverridesClientFalseAsyncSuppression(): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::once())
            ->method('run')
            ->willReturn($this->successfulMtfResponse());
        $validator->expects(self::never())->method('getListTimeframe');
        $tradeDecisionDispatcher = $this->createMock(TradeDecisionDispatcherInterface::class);
        $tradeDecisionDispatcher->expects(self::never())->method('dispatchFromResponse');

        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v2',
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
                'suppress_exchange_capable_async_work' => false,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller()->index(
            $request,
            new RunMtfCycleUseCase($this->runnerService($validator, $tradeDecisionDispatcher)),
        );
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertIsArray($body);
        self::assertTrue(
            $body['data']['fake_only_safety_evidence']['async_exchange_capable_dispatches_suppressed'] ?? false,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProofPayloads')]
    public function testRejectsFakeOnlyProofOutsideFakeDryRun(array $payload): void
    {
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method('run');
        $controller = $this->controller();
        $request = Request::create(
            '/api/mtf/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_FAKE_ONLY_SAFETY_EVIDENCE' => 'v2',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response = $controller->index($request, new RunMtfCycleUseCase($this->runnerService($validator)));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('fake_only_safety_context_invalid', $body['error_code'] ?? null);
        self::assertArrayNotHasKey('fake_only_safety_evidence', $body['data'] ?? []);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalidProofPayloads(): iterable
    {
        $base = [
            'symbols' => ['BTCUSDT'],
            'market_type' => 'perpetual',
            'workers' => 1,
            'sync_tables' => false,
            'process_tp_sl' => false,
            'skip_open_state_filter' => true,
        ];

        yield 'real exchange dry-run' => [$base + ['exchange' => 'bitmart', 'dry_run' => true]];
        yield 'mutative fake' => [$base + ['exchange' => 'fake', 'dry_run' => false]];
        yield 'parallel Fake worker escapes request-scoped audit' => [array_replace(
            $base,
            ['exchange' => 'fake', 'dry_run' => true, 'workers' => 2],
        )];
    }

    /** @param array<int, array{name: string, enabled: bool, priority: int}> $enabledModes */
    private function controller(array $enabledModes = [], ?LoggerInterface $logger = null): RunnerController
    {
        $controller = new RunnerController(
            $logger ?? new NullLogger(),
            $this->modeContext($enabledModes),
            new OrchestrationContextValidator(),
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $controller->setContainer($container);

        return $controller;
    }

    private function successfulMtfResponse(): MtfRunResponseDto
    {
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

    /** @return array<string, mixed> */
    private static function canonicalTradingIdentity(): array
    {
        $catalogHash = 'sha256:' . str_repeat('b', 64);
        $config = ['trade_entry' => ['defaults' => [], 'entry' => [], 'risk' => [], 'leverage' => [], 'decision' => [], 'fees' => []]];
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, $catalogHash);
        return [
            'mode_id' => 'scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback.long',
            'setup_version' => '1.0.0',
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'side' => 'LONG',
            'effective_config_reference' => 'effective-config:cfg-1',
            'effective_config_snapshot' => CanonicalSnapshotMetadataFixture::enrich([
                'request' => ['mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'exchange' => 'fake', 'environment' => 'test', 'side' => 'long'],
                'config' => $config, 'config_hash' => $configHash, 'condition_catalog_hash' => $catalogHash,
                'executable' => true, 'blockers' => [],
            ]),
        ];
    }

    private function runnerService(
        MtfValidatorInterface $validator,
        ?TradeDecisionDispatcherInterface $tradeDecisionDispatcher = null,
        ?ContractRepository $contractRepository = null,
        ?MainProviderInterface $mainProvider = null,
        ?AuditLoggerInterface $auditLogger = null,
    ): MtfRunnerService
    {
        $logger = new NullLogger();
        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $mainProvider ??= $this->createMock(MainProviderInterface::class);

        return new MtfRunnerService(
            new SymbolUniverseResolver(
                $contractRepository ?? $this->createMock(ContractRepository::class),
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
            $tradeDecisionDispatcher ?? $this->createMock(TradeDecisionDispatcherInterface::class),
            new CanonicalMtfPolicyPreflight(),
            $auditLogger ?? $this->createMock(AuditLoggerInterface::class),
            '/tmp',
            $this->createMock(ClockInterface::class),
        );
    }

    /** @return array<int, array{name: string, enabled: bool, priority: int}> */
    private function enabledModes(): array
    {
        return [
            ['name' => 'scalper', 'enabled' => true, 'priority' => 1],
            ['name' => 'regular', 'enabled' => true, 'priority' => 2],
        ];
    }

    /** @param array<int, array{name: string, enabled: bool, priority: int}> $enabledModes */
    private function modeContext(array $enabledModes = []): TradeEntryModeContext
    {
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturnMap([
            ['kernel.project_dir', '/tmp'],
            ['mode', array_map(
                static fn(array $mode): array => [
                    ['name' => $mode['name']],
                    ['enabled' => $mode['enabled']],
                    ['priority' => $mode['priority']],
                ],
                $enabledModes,
            )],
        ]);

        return new TradeEntryModeContext(
            new TradeEntryConfigProvider($parameterBag),
            'regular',
            new NullLogger(),
        );
    }
}
