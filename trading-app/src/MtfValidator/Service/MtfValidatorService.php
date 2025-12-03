<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Config\MtfValidationConfig;
use App\Config\MtfValidationConfigProvider;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\MtfValidator\Message\MtfResultProjectionMessage;
use App\MtfValidator\Message\MtfTradingDecisionMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

class MtfValidatorService implements MtfValidatorInterface
{
    public function __construct(
        private readonly MtfValidatorCoreService $core,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
        private readonly MtfValidationConfigProvider $mtfValidationConfigProvider,
        #[Autowire('%app.trade_entry_default_mode%')]
        private readonly string $defaultProfile,
    ) {
    }

    public function run(MtfRunRequestDto $request): MtfRunResponseDto
    {
        $startedAt = $this->clock->now();
        $runId     = Uuid::uuid4()->toString();

        $results           = [];
        $errors            = [];
        $symbolsRequested  = \count($request->symbols);
        $symbolsProcessed  = 0;
        $symbolsSuccessful = 0;
        $symbolsFailed     = 0;
        $symbolsSkipped    = 0;

        $profile = $request->profile ?? $this->defaultProfile;
        $mode    = $request->mode;

        foreach ($request->symbols as $symbol) {
            $symbolsProcessed++;

            try {
                $mtfRunDto = new MtfRunDto(
                    symbol: $symbol,
                    profile: $profile,
                    mode: $mode,
                    now: $this->clock->now(),
                    requestId: $runId,
                    dryRun: $request->dryRun,
                    options: [
                        'dry_run'               => $request->dryRun,
                        'force_run'             => $request->forceRun,
                        'current_tf'            => $request->currentTf,
                        'force_timeframe_check' => $request->forceTimeframeCheck,
                        'skip_context'          => $request->skipContextValidation,
                        'lock_per_symbol'       => $request->lockPerSymbol,
                        'skip_open_state'       => $request->skipOpenStateFilter,
                        'user_id'               => $request->userId,
                        'ip_address'            => $request->ipAddress,
                        'exchange'              => $request->exchange?->value,
                        'market_type'           => $request->marketType?->value,
                    ],
                );

                $result = $this->core->validate($mtfRunDto);
                $results[] = [
                    'symbol' => $symbol,
                    'result' => $result,
                ];

                if (!$request->dryRun) {
                    $this->messageBus->dispatch(new MtfResultProjectionMessage($runId, $mtfRunDto, $result));
                }

                if ($result->isTradable) {
                    $symbolsSuccessful++;
                    $this->messageBus->dispatch(new MtfTradingDecisionMessage($runId, $mtfRunDto, $result));
                } else {
                    $symbolsSkipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'symbol'  => $symbol,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ];
                $symbolsFailed++;
            }
        }

        if (!$request->dryRun && $this->em->isOpen()) {
            $this->em->flush();
        }

        $endedAt = $this->clock->now();
        $executionTimeSeconds = max(
            0.0,
            $endedAt->getTimestamp() - $startedAt->getTimestamp()
        );

        $totalProcessed = $symbolsSuccessful + $symbolsFailed + $symbolsSkipped;
        $successRate = $totalProcessed > 0
            ? ($symbolsSuccessful / $totalProcessed) * 100
            : 0.0;

        // Statut global (on reprend la logique du MtfRunService legacy)
        $status = 'success';
        if ($symbolsFailed > 0 && $symbolsSuccessful === 0) {
            $status = 'error';
        } elseif ($symbolsFailed > 0 && $symbolsSuccessful > 0) {
            $status = 'partial_failure';
        }

        return new MtfRunResponseDto(
            runId: $runId,
            status: $status,
            executionTimeSeconds: $executionTimeSeconds,
            symbolsRequested: $symbolsRequested,
            symbolsProcessed: $totalProcessed,
            symbolsSuccessful: $symbolsSuccessful,
            symbolsFailed: $symbolsFailed,
            symbolsSkipped: $symbolsSkipped,
            successRate: $successRate,
            results: $results,
            errors: $errors,
            timestamp: $endedAt,
            message: null,
        );
    }

    public function getServiceName(): string
    {
        return 'mtf_validator';
    }

    public function getListTimeframe(string $profile = 'scalper'): array
    {
        $config = $this->mtfValidationConfigProvider->getConfigForMode($profile)->getConfig();
        return array_merge($config['context_timeframes'], $config['execution_timeframes']);
    }
}
