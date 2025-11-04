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
            $generator = $this->orchestrator->execute($mtfRunDto, $runIdUuid);

            $streamedResults = [];
            $summaryPayload = null;

            foreach ($generator as $result) {
                if (isset($result['summary'])) {
                    $summaryPayload = $result['summary'];
                    continue;
                }

                $streamedResults[] = $result;
            }

            $final = $generator->getReturn();
            $finalResults = [];
            if (is_array($final)) {
                $summaryPayload = $final['summary'] ?? $summaryPayload;
                if (isset($final['results']) && is_array($final['results'])) {
                    $finalResults = $final['results'];
                }
            }

            $executionTime = microtime(true) - $startTime;

            $resultsForStats = $finalResults !== []
                ? $finalResults
                : self::indexResultsBySymbol($streamedResults);

            $status = 'success';
            $symbolsSuccessful = 0;
            $symbolsFailed = 0;
            $symbolsSkipped = 0;
            $errors = [];

            foreach ($resultsForStats as $result) {
                $result = (array) $result;
                $state = strtoupper((string) ($result['status'] ?? ''));
                switch ($state) {
                    case 'SUCCESS':
                        $symbolsSuccessful++;
                        break;
                    case 'ERROR':
                        $symbolsFailed++;
                        $errors[] = $result;
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

            return ContractMapper::toContractResponse(
                $internalSummary,
                $streamedResults,
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
