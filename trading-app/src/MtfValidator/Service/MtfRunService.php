<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\MtfValidator\Service\Dto\Mapper\ContractMapper;
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
        $startedAt = new \DateTimeImmutable();

        $this->logger->info('[MTF Run] Starting execution', [
            'run_id' => $runId,
            'symbols_count' => count($request->symbols),
            'dry_run' => $request->dryRun,
            'force_run' => $request->forceRun,
        ]);

        try {
            $internalRequest = ContractMapper::fromContractRequest($runId, $request, $startedAt);
            $mtfRunDto = ContractMapper::toContractRunDto($internalRequest);

            $runIdUuid = Uuid::fromString($runId);
            $executionResults = $this->orchestrator->execute($mtfRunDto, $runIdUuid);

            $progressEvents = [];
            $symbolResults = [];
            $summaryPayload = null;

            foreach ($executionResults as $result) {
                if (!is_array($result)) {
                    continue;
                }

                if (isset($result['summary']) && is_array($result['summary'])) {
                    $summaryPayload = $result['summary'];
                    if (isset($result['results']) && is_array($result['results'])) {
                        $symbolResults = $result['results'] + $symbolResults;
                    }
                    continue;
                }

                if (($result['symbol'] ?? null) === 'FINAL') {
                    if (isset($result['result']) && is_array($result['result'])) {
                        $summaryPayload = $result['result'];
                    }
                    if (isset($result['results']) && is_array($result['results'])) {
                        $symbolResults = $symbolResults + $result['results'];
                    }
                    continue;
                }

                if (isset($result['symbol'], $result['result']) && is_array($result['result'])) {
                    $symbolResults[(string) $result['symbol']] = $result['result'];
                }

                $progressEvents[] = $result;
            }

            $finalResults = $symbolResults;
            if ($executionResults instanceof \Generator) {
                $final = $executionResults->getReturn();
                if (is_array($final)) {
                    if (isset($final['summary']) && is_array($final['summary'])) {
                        $summaryPayload = $final['summary'];
                    }
                    if (isset($final['results']) && is_array($final['results'])) {
                        $finalResults = $final['results'] + $finalResults;
                    }
                }
            }

            if ($finalResults === []) {
                $finalResults = self::indexResultsBySymbol($progressEvents);
            }

            $executionTime = microtime(true) - $startTime;

            $status = 'success';
            $symbolsSuccessful = 0;
            $symbolsFailed = 0;
            $symbolsSkipped = 0;
            $errors = [];

            foreach ($finalResults as $symbol => $result) {
                $result = (array) $result;
                $state = strtoupper((string) ($result['status'] ?? ''));
                switch ($state) {
                    case 'SUCCESS':
                        $symbolsSuccessful++;
                        break;
                    case 'ERROR':
                        $symbolsFailed++;
                        $errors[] = [
                            'symbol' => is_string($symbol) ? $symbol : ($result['symbol'] ?? null),
                            'details' => $result,
                        ];
                        break;
                    case 'SKIPPED':
                    case 'GRACE_WINDOW':
                        $symbolsSkipped++;
                        break;
                }
            }

            if ($symbolsFailed > 0 && $symbolsSuccessful > 0) {
                $status = 'partial_success';
            } elseif ($symbolsFailed > 0) {
                $status = 'error';
            }

            $totalProcessed = $symbolsSuccessful + $symbolsFailed + $symbolsSkipped;
            $successRate = $totalProcessed > 0 ? round(($symbolsSuccessful / $totalProcessed) * 100, 2) : 0.0;

            $internalSummary = ContractMapper::toInternalSummary(
                $internalRequest,
                $executionTime,
                $totalProcessed,
                $symbolsSuccessful,
                $symbolsFailed,
                $symbolsSkipped,
                $successRate,
                $status,
                $summaryPayload
            );

            $resultsForResponse = $finalResults !== [] ? $finalResults : $progressEvents;

            return ContractMapper::toContractResponse(
                $internalSummary,
                $resultsForResponse,
                $errors
            );
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Run] Execution failed', [
                'run_id' => $runId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * @param array<int, array> $streamedResults
     * @return array<string, array>
     */
    private static function indexResultsBySymbol(array $streamedResults): array
    {
        $indexed = [];

        foreach ($streamedResults as $entry) {
            if (!is_array($entry) || !isset($entry['symbol'], $entry['result'])) {
                continue;
            }

            $symbol = (string) $entry['symbol'];
            if ($symbol === 'FINAL') {
                continue;
            }

            $indexed[$symbol] = (array) $entry['result'];
        }

        return $indexed;
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
