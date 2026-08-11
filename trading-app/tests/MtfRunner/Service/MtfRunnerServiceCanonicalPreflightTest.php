<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Service;

use App\Application\Runner\ExchangeStateSynchronizer;
use App\Application\Runner\OpenActivityFilter;
use App\Application\Runner\PostRunProjectionDispatcher;
use App\Application\Runner\RunResultAssembler;
use App\Application\Runner\SymbolUniverseResolver;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfRunner\Application\Result\MtfRunResultEnricher;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\MtfRunner\Service\MtfRunnerService;
use App\MtfValidator\Application\TradeDecisionDispatcherInterface;
use App\MtfValidator\Policy\CanonicalMtfPolicyPreflight;
use App\MtfValidator\Repository\MtfLockRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Repository\ContractRepository;
use App\Repository\PositionRepository;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(MtfRunnerService::class)]
final class MtfRunnerServiceCanonicalPreflightTest extends TestCase
{
    public function testExecutableModernRequestIsRejectedBeforeParallelAndPeripheralWork(): void
    {
        $secret = 'TOP-SECRET';
        $config = CanonicalSnapshotFixture::config();
        $config['exchange']['api_key'] = $secret;
        $identity = CanonicalSnapshotFixture::lineage($config);
        $blockers = [
            ['code' => 'canonical_risk_pct_pending_304', 'path' => 'runtime.trade_entry.risk_pct'],
            ['code' => 'canonical_daily_loss_policy_pending_304', 'path' => 'mode.risk.daily_loss_cap'],
            ['code' => 'canonical_end_of_zone_fallback_pending_304', 'path' => 'runtime.trade_entry.fallback_end_of_zone'],
            ['code' => 'canonical_max_concurrent_positions_pending_304', 'path' => 'mode.risk.max_concurrent_positions'],
            ['code' => 'canonical_mode_exposure_cap_pending_304', 'path' => 'mode.risk.mode_exposure_cap'],
            ['code' => 'canonical_minimum_net_r_pending_304', 'path' => 'setup.ast.execution.minimum_net_r'],
        ];

        $diagnostics = [];
        $result = $this->strictService(
            identity: $identity,
            reason: 'canonical_risk_pct_pending_304',
            blockers: $blockers,
            diagnostics: $diagnostics,
        )->run($this->modernRequest($identity));

        self::assertSame('rejected', $result['summary']['status'] ?? null);
        self::assertSame('canonical_policy_rejected', $result['summary']['canonical_status'] ?? null);
        self::assertSame('canonical_risk_pct_pending_304', $result['summary']['reason'] ?? null);
        self::assertSame('Canonical MTF request rejected by runtime policy.', $result['summary']['message'] ?? null);
        self::assertSame(2, $result['summary']['symbols_requested'] ?? null);
        self::assertSame($blockers, $result['summary']['canonical_policy_blockers'] ?? null);
        self::assertSame($identity->redacted(), $result['summary']['lineage'] ?? null);
        self::assertArrayNotHasKey('effective_config_snapshot', $diagnostics['logger']['identity'] ?? []);
        self::assertArrayNotHasKey('effective_config_snapshot', $diagnostics['audit']['identity'] ?? []);
        self::assertArrayNotHasKey('effective_config_snapshot', $result['summary']['lineage']);
        self::assertStringNotContainsString(
            $secret,
            json_encode([
                'logger' => $diagnostics['logger'] ?? null,
                'audit' => $diagnostics['audit'] ?? null,
                'summary' => $result['summary']['lineage'],
            ], JSON_THROW_ON_ERROR),
        );
    }

    public function testNonExecutableModernSnapshotReturnsTypedCanonicalReason(): void
    {
        $payload = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $payload['effective_config_snapshot']['executable'] = false;
        $payload['effective_config_snapshot']['blockers'] = ['mode.status:draft'];
        $payload['effective_config_snapshot']['snapshot_hash'] = CanonicalEffectiveConfigSnapshot::calculateSnapshotHash(
            $payload['effective_config_snapshot'],
        );
        $identity = LineageContext::fromArray($payload);
        $blockers = [[
            'code' => 'canonical_contract_not_executable',
            'path' => 'effective_config_snapshot',
        ]];

        $diagnostics = [];
        $result = $this->strictService(
            identity: $identity,
            reason: 'canonical_contract_not_executable',
            blockers: $blockers,
            diagnostics: $diagnostics,
        )->run($this->modernRequest($identity));

        self::assertSame('rejected', $result['summary']['status'] ?? null);
        self::assertSame('canonical_contract_not_executable', $result['summary']['reason'] ?? null);
        self::assertSame($blockers, $result['summary']['canonical_policy_blockers'] ?? null);
    }

    /**
     * Build the final runner collaborators for real and make every leaf dependency
     * strict. A workers=8 rejection can therefore pass only if it returns before
     * symbol resolution, exchange sync/filter, validation, process spawning,
     * projection, trade dispatch, locks/switches, and TP/SL provider access.
     *
     * @param list<array{code:string,path:string}> $blockers
     * @param array<string,array<string,mixed>> $diagnostics
     */
    private function strictService(
        LineageContext $identity,
        string $reason,
        array $blockers,
        array &$diagnostics,
    ): MtfRunnerService {
        $runId = 'run-fixture';
        $context = [
            'run_id' => $runId,
            'reason' => $reason,
            'blockers' => $blockers,
            'identity' => $identity->redacted(),
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());
        $positionsLogger = $this->createMock(LoggerInterface::class);
        $positionsLogger->expects(self::never())->method(self::anything());
        $mtfLogger = $this->createMock(LoggerInterface::class);
        $mtfLogger->expects(self::once())
            ->method('warning')
            ->with(
                'mtf.runner.canonical_policy_rejected',
                self::callback(static function (array $actual) use (&$diagnostics, $context): bool {
                    $diagnostics['logger'] = $actual;

                    return $actual === $context;
                }),
            );
        $mtfLogger->expects(self::never())->method('info');
        $mtfLogger->expects(self::never())->method('debug');
        $mtfLogger->expects(self::never())->method('error');

        $switchRepository = $this->createMock(MtfSwitchRepository::class);
        $switchRepository->expects(self::never())->method(self::anything());
        $lockRepository = $this->createMock(MtfLockRepository::class);
        $lockRepository->expects(self::never())->method(self::anything());
        $contractRepository = $this->createMock(ContractRepository::class);
        $contractRepository->expects(self::never())->method(self::anything());
        $positionRepository = $this->createMock(PositionRepository::class);
        $positionRepository->expects(self::never())->method(self::anything());

        $syncProvider = $this->createMock(MainProviderInterface::class);
        $syncProvider->expects(self::never())->method(self::anything());
        $filterProvider = $this->createMock(MainProviderInterface::class);
        $filterProvider->expects(self::never())->method(self::anything());
        $tpSlProvider = $this->createMock(MainProviderInterface::class);
        $tpSlProvider->expects(self::never())->method(self::anything());
        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->expects(self::never())->method(self::anything());
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method(self::anything());
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method(self::anything());
        $tradeDecisionDispatcher = $this->createMock(TradeDecisionDispatcherInterface::class);
        $tradeDecisionDispatcher->expects(self::never())->method(self::anything());

        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('logAction')->with(
            'MTF_CANONICAL_POLICY_REJECTED',
            'MTF_RUN',
            $runId,
            self::callback(static function (array $actual) use (&$diagnostics, $context): bool {
                $diagnostics['audit'] = $actual;

                return $actual === $context;
            }),
            'canonical-user',
            '203.0.113.42',
        );
        foreach ([
            'logCreate',
            'logUpdate',
            'logDelete',
            'logRead',
            'logTradingAction',
            'logError',
            'logUserAccess',
            'logConfigChange',
            'logSecurityEvent',
            'getAuditLogs',
            'getAuditStats',
        ] as $method) {
            $audit->expects(self::never())->method($method);
        }

        return new MtfRunnerService(
            new SymbolUniverseResolver($contractRepository, $switchRepository, $logger, $mtfLogger),
            new OpenActivityFilter($filterProvider, $switchRepository, $logger, $mtfLogger),
            new ExchangeStateSynchronizer($syncProvider, $positionRepository, $logger, $positionsLogger),
            new PostRunProjectionDispatcher($validator, $messageBus, $clock, $logger),
            new RunResultAssembler(new MtfRunResultEnricher()),
            $lockRepository,
            $switchRepository,
            $validator,
            $tpSlProvider,
            $logger,
            $mtfLogger,
            $positionsLogger,
            $tradeDecisionDispatcher,
            new CanonicalMtfPolicyPreflight(),
            $audit,
            '/definitely-not-a-worker-project',
            $clock,
        );
    }

    private function modernRequest(LineageContext $identity): MtfRunnerRequestDto
    {
        return new MtfRunnerRequestDto(
            symbols: ['BTCUSDT', 'ETHUSDT'],
            dryRun: true,
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            workers: 8,
            syncTables: true,
            processTpSl: true,
            profile: 'scalping',
            originalRunId: 'run-fixture',
            correlationRunId: 'run-fixture',
            setId: 'set-fixture',
            userId: 'canonical-user',
            ipAddress: '203.0.113.42',
            lineageContext: $identity,
        );
    }
}
