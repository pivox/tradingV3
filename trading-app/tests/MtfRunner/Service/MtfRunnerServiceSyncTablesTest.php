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
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\Provider\MainProviderInterface;
use App\MtfRunner\Application\Result\MtfRunResultEnricher;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\MtfRunner\Service\MtfRunnerService;
use App\MtfValidator\Application\TradeDecisionDispatcherInterface;
use App\MtfValidator\Repository\MtfLockRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Provider\Repository\ContractRepository;
use App\Repository\PositionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * SF-002 — vérifie que `/api/mtf/run` (via MtfRunnerService) honore le flag
 * `sync_tables`. ExchangeStateSynchronizer étant `final`, on l'instancie réellement
 * avec un MainProvider mocké et on observe l'appel à `forContext()` comme preuve que
 * la synchro des tables a (ou n'a pas) touché l'exchange.
 */
#[CoversClass(MtfRunnerService::class)]
final class MtfRunnerServiceSyncTablesTest extends TestCase
{
    public function testSyncTablesHitsExchangeWhenFlagIsTrue(): void
    {
        $syncProvider = $this->createMock(MainProviderInterface::class);
        $syncProvider->expects(self::once())->method('forContext')->willReturnSelf();
        $syncProvider->method('getAccountProvider')->willReturn(null);
        $syncProvider->method('getOrderProvider')->willReturn(null);

        $service = $this->buildService($syncProvider);

        $service->run($this->request(syncTables: true));
    }

    public function testSyncTablesIsSkippedWhenFlagIsFalse(): void
    {
        $syncProvider = $this->createMock(MainProviderInterface::class);
        $syncProvider->expects(self::never())->method('forContext');

        // Assertion directe (et non plus seulement un proxy d'internals) : le runner
        // doit tracer le skip de l'upsert via le canal mtf.
        $skipLogged = false;
        $mtfLogger = $this->createMock(LoggerInterface::class);
        $mtfLogger->method('debug')->willReturnCallback(
            function (string $message) use (&$skipLogged): void {
                if (str_contains($message, 'Skipping exchange table upsert')) {
                    $skipLogged = true;
                }
            }
        );

        $service = $this->buildService($syncProvider, $mtfLogger);

        $service->run($this->request(syncTables: false));

        self::assertTrue($skipLogged, 'Le skip de la synchro doit être tracé quand sync_tables=false.');
    }

    private function request(bool $syncTables): MtfRunnerRequestDto
    {
        // Pas de profil : couvre aussi le chemin sans profil (cf. guard resolveTimeframes).
        return new MtfRunnerRequestDto(
            symbols: ['BTCUSDT'],
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            workers: 1,
            syncTables: $syncTables,
            processTpSl: false,
        );
    }

    /**
     * Construit le service avec ses collaborateurs réels. Plusieurs d'entre eux
     * (SymbolUniverseResolver, OpenActivityFilter, ExchangeStateSynchronizer...) sont
     * déclarés `final` et ne peuvent donc pas être doublés : on les instancie réellement
     * en mockant uniquement leurs dépendances feuilles (interfaces / repositories).
     */
    private function buildService(MainProviderInterface $syncProvider, ?LoggerInterface $mtfLogger = null): MtfRunnerService
    {
        $logger = $this->createMock(LoggerInterface::class);
        $mtfLogger ??= $this->createMock(LoggerInterface::class);
        $switchRepository = $this->createMock(MtfSwitchRepository::class);

        $synchronizer = new ExchangeStateSynchronizer(
            $syncProvider,
            $this->createMock(PositionRepository::class),
            $logger,
            $logger,
        );

        $symbolResolver = new SymbolUniverseResolver(
            $this->createMock(ContractRepository::class),
            $switchRepository,
            $logger,
            $logger,
        );

        // Provider neutre (aucun account/order provider) → le filtre laisse passer les symboles.
        $filterProvider = $this->createMock(MainProviderInterface::class);
        $filterProvider->method('forContext')->willReturnSelf();
        $filterProvider->method('getAccountProvider')->willReturn(null);
        $filterProvider->method('getOrderProvider')->willReturn(null);

        $openActivityFilter = new OpenActivityFilter(
            $filterProvider,
            $switchRepository,
            $logger,
            $logger,
        );

        $validator = $this->createMock(MtfValidatorInterface::class);
        $validator->method('run')->willReturn($this->emptyResponse());
        $validator->method('getListTimeframe')->willReturn([]);

        $projectionDispatcher = new PostRunProjectionDispatcher(
            $validator,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(ClockInterface::class),
            $logger,
        );

        $resultAssembler = new RunResultAssembler(new MtfRunResultEnricher());

        return new MtfRunnerService(
            $symbolResolver,
            $openActivityFilter,
            $synchronizer,
            $projectionDispatcher,
            $resultAssembler,
            $this->createMock(MtfLockRepository::class),
            $switchRepository,
            $validator,
            $this->createMock(MainProviderInterface::class),
            $logger,
            $mtfLogger,
            $logger,
            $this->createMock(TradeDecisionDispatcherInterface::class),
            '/tmp',
            $this->createMock(ClockInterface::class),
            null,
            null,
            null,
        );
    }

    private function emptyResponse(): MtfRunResponseDto
    {
        return new MtfRunResponseDto(
            runId: 'test-run',
            status: 'success',
            executionTimeSeconds: 0.0,
            symbolsRequested: 0,
            symbolsProcessed: 0,
            symbolsSuccessful: 0,
            symbolsFailed: 0,
            symbolsSkipped: 0,
            successRate: 0.0,
            results: [],
            errors: [],
            timestamp: new \DateTimeImmutable(),
        );
    }
}
