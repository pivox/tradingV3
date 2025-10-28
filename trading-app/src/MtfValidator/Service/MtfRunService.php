<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\MtfValidator\Service\Dto\InternalMtfRunDto;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
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
        private readonly LoggerInterface $logger
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
                lockPerSymbol: $request->lockPerSymbol
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
}
