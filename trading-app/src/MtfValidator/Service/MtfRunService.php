<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\MtfValidator\Service\Dto\InternalMtfRunDto;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use App\TradeEntry\Service\TpSlTwoTargetsService;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Types\Side as EntrySide;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Common\Enum\OrderSide;
use App\Common\Enum\PositionSide;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsAlias(id: MtfValidatorInterface::class)]
#[AutoconfigureTag('app.mtf.validator')]
class MtfRunService implements MtfValidatorInterface
{
    public function __construct(
        private readonly MtfRunOrchestrator $orchestrator,
        private readonly LoggerInterface $logger,
        private readonly ?TpSlTwoTargetsService $tpSlService = null,
        private readonly ?MainProviderInterface $mainProvider = null,
    ) {}

    /**
     * Exécute un cycle MTF en utilisant les contrats
     */
    public function run(MtfRunRequestDto $request): MtfRunResponseDto
    {
        $runId = Uuid::uuid4()->toString();
        $startTime = microtime(true);
        
        $this->logger->info('[MTF Run] Starting execution', [
            'run_id' => $runId,
            'symbols_count' => count($request->symbols),
            'dry_run' => $request->dryRun,
            'force_run' => $request->forceRun
        ]);

        try {
            // Convertir la requête en format compatible avec l'orchestrateur existant
            $mtfRunDto = new \App\Contract\MtfValidator\Dto\MtfRunDto(
                symbols: $request->symbols,
                dryRun: $request->dryRun,
                forceRun: $request->forceRun,
                currentTf: $request->currentTf,
                forceTimeframeCheck: $request->forceTimeframeCheck,
                skipContextValidation: $request->skipContextValidation,
                lockPerSymbol: $request->lockPerSymbol,
                skipOpenStateFilter: $request->skipOpenStateFilter,
            );
            
            $runIdUuid = \Ramsey\Uuid\Uuid::fromString($runId);
            $generator = $this->orchestrator->execute($mtfRunDto, $runIdUuid);
            
            // Collecter tous les résultats du generator
            $results = [];
            $summary = null;
            foreach ($generator as $result) {
                if (isset($result['summary'])) {
                    $summary = $result['summary'];
                } else {
                    $results[] = $result;
                }
            }
            
            $executionTime = microtime(true) - $startTime;
            
            // Construire la réponse basée sur les résultats
            $status = 'success';
            $symbolsSuccessful = 0;
            $symbolsFailed = 0;
            $symbolsSkipped = 0;
            $errors = [];
            
            foreach ($results as $result) {
                if (isset($result['result']['status'])) {
                    switch ($result['result']['status']) {
                        case 'SUCCESS':
                            $symbolsSuccessful++;
                            break;
                        case 'ERROR':
                            $symbolsFailed++;
                            $errors[] = $result['result'];
                            break;
                        case 'SKIPPED':
                        case 'GRACE_WINDOW':
                            $symbolsSkipped++;
                            break;
                    }
                }
            }
            
            if ($symbolsFailed > 0 && $symbolsSuccessful > 0) {
                $status = 'partial_success';
            } elseif ($symbolsFailed > 0) {
                $status = 'error';
            }
            
            $totalProcessed = $symbolsSuccessful + $symbolsFailed + $symbolsSkipped;
            $successRate = $totalProcessed > 0 ? ($symbolsSuccessful / $totalProcessed) * 100 : 0;
            
            // Calcul et mise à jour des TP/SL pour les positions avec exactement 1 ordre TP
            if ($this->tpSlService !== null && $this->mainProvider !== null) {
                $this->processTpSlRecalculation($request->dryRun);
            }
            
            return new MtfRunResponseDto(
                runId: $runId,
                status: $status,
                executionTimeSeconds: $executionTime,
                symbolsRequested: count($request->symbols),
                symbolsProcessed: $totalProcessed,
                symbolsSuccessful: $symbolsSuccessful,
                symbolsFailed: $symbolsFailed,
                symbolsSkipped: $symbolsSkipped,
                successRate: $successRate,
                results: $results,
                errors: $errors,
                timestamp: new \DateTimeImmutable(),
                message: $summary['message'] ?? null
            );
            
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] Execution failed', [
                'run_id' => $runId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function healthCheck(): bool
    {
        try {
            // Vérification simple de la santé du service
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] Health check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getServiceName(): string
    {
        return 'MtfRunService';
    }

    /**
     * Traite le recalcul des TP/SL pour les positions avec exactement 1 ordre TP
     */
    private function processTpSlRecalculation(bool $dryRun): void
    {
        try {
            $accountProvider = $this->mainProvider->getAccountProvider();
            $orderProvider = $this->mainProvider->getOrderProvider();
            
            if ($accountProvider === null || $orderProvider === null) {
                $this->logger->warning('[MTF Run] TP/SL recalculation skipped: missing providers');
                return;
            }

            // Récupérer toutes les positions ouvertes
            $openPositions = $accountProvider->getOpenPositions();
            $this->logger->info('[MTF Run] TP/SL recalculation: checking positions', [
                'count' => count($openPositions),
                'dry_run' => $dryRun,
            ]);

            foreach ($openPositions as $position) {
                try {
                    $symbol = strtoupper($position->symbol);
                    $positionSide = $position->side;
                    
                    // Convertir PositionSide en EntrySide
                    $entrySide = $positionSide === PositionSide::LONG 
                        ? EntrySide::Long 
                        : EntrySide::Short;
                    
                    // Validation: s'assurer que le side est valide
                    if ($positionSide !== PositionSide::LONG && $positionSide !== PositionSide::SHORT) {
                        $this->logger->warning('[MTF Run] TP/SL recalculation skipped: invalid position side', [
                            'symbol' => $symbol,
                            'side' => $positionSide->value ?? 'unknown',
                        ]);
                        continue;
                    }
                    
                    // Récupérer les ordres ouverts pour ce symbole
                    $openOrders = $orderProvider->getOpenOrders($symbol);
                    
                    // Déterminer le côté de fermeture
                    $closeSide = $entrySide === EntrySide::Long ? OrderSide::SELL : OrderSide::BUY;
                    
                    // Filtrer les ordres de fermeture
                    $closingOrders = array_filter($openOrders, fn(OrderDto $o) => $o->side === $closeSide);
                    
                    if (empty($closingOrders)) {
                        continue; // Pas d'ordres de fermeture, passer
                    }
                    
                    // Identifier les SL et TP
                    $entryPrice = (float)$position->entryPrice->__toString();
                    $slOrders = [];
                    $tpOrders = [];
                    
                    foreach ($closingOrders as $order) {
                        if ($order->price === null) {
                            continue;
                        }
                        
                        $orderPrice = (float)$order->price->__toString();
                        
                        // Un SL est un ordre avec prix < entryPrice (long) ou prix > entryPrice (short)
                        $isSl = $entrySide === EntrySide::Long 
                            ? ($orderPrice < $entryPrice) 
                            : ($orderPrice > $entryPrice);
                        
                        if ($isSl) {
                            $slOrders[] = $order;
                        } else {
                            $tpOrders[] = $order;
                        }
                    }
                    
                    // Critère: recalculer seulement si exactement 1 ordre TP
                    if (count($tpOrders) !== 1) {
                        $this->logger->debug('[MTF Run] TP/SL recalculation skipped', [
                            'symbol' => $symbol,
                            'tp_count' => count($tpOrders),
                            'reason' => count($tpOrders) === 0 ? 'no_tp_orders' : 'multiple_tp_orders',
                        ]);
                        continue;
                    }
                    
                    // Recalculer les TP/SL
                    $this->logger->info('[MTF Run] TP/SL recalculation: processing', [
                        'symbol' => $symbol,
                        'side' => $entrySide->value,
                        'entry_price' => $entryPrice,
                        'size' => (float)$position->size->__toString(),
                        'dry_run' => $dryRun,
                    ]);
                    
                    $request = new TpSlTwoTargetsRequest(
                        symbol: $symbol,
                        side: $entrySide,
                        entryPrice: $entryPrice,
                        size: (int)(float)$position->size->__toString(),
                        dryRun: $dryRun,
                        cancelExistingStopLossIfDifferent: true,
                        cancelExistingTakeProfits: true,
                    );
                    
                    $result = $this->tpSlService->__invoke($request, 'mtf_run_' . time());
                    
                    $this->logger->info('[MTF Run] TP/SL recalculation: completed', [
                        'symbol' => $symbol,
                        'sl' => $result['sl'],
                        'tp1' => $result['tp1'],
                        'tp2' => $result['tp2'],
                        'submitted_count' => count($result['submitted']),
                        'cancelled_count' => count($result['cancelled']),
                        'dry_run' => $dryRun,
                    ]);
                    
                } catch (\Throwable $e) {
                    $this->logger->error('[MTF Run] TP/SL recalculation failed for position', [
                        'symbol' => $position->symbol ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continuer avec les autres positions
                }
            }
            
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] TP/SL recalculation process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Ne pas faire échouer le run MTF complet
        }
    }
}
