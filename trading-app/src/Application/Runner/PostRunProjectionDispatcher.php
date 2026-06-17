<?php

declare(strict_types=1);

namespace App\Application\Runner;

use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Indicator\Message\IndicatorSnapshotPersistRequestMessage;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class PostRunProjectionDispatcher
{
    public function __construct(
        private readonly MtfValidatorInterface $mtfValidator,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $results
     */
    public function dispatch(array $results, MtfRunnerRequestDto $request, string $runId): void
    {
        $timeframes = $this->resolveTimeframes($request);
        if ($timeframes === []) {
            return;
        }

        $symbols = [];
        foreach (array_keys($results) as $symbol) {
            if (!\is_string($symbol) || $symbol === '' || strtoupper($symbol) === 'FINAL') {
                continue;
            }
            $symbols[] = strtoupper($symbol);
        }

        if ($symbols === []) {
            return;
        }

        try {
            $this->messageBus->dispatch(new IndicatorSnapshotPersistRequestMessage(
                $symbols,
                $timeframes,
                $runId,
                $request->profile,
                $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $request->exchange?->value ?? 'bitmart',
                $request->marketType?->value ?? 'perpetual',
            ));

            $this->logger->debug('[MTF Runner] Indicator persistence dispatched', [
                'run_id' => $runId,
                'exchange' => $request->exchange?->value ?? 'bitmart',
                'market_type' => $request->marketType?->value ?? 'perpetual',
                'symbols_count' => count($symbols),
                'timeframes' => $timeframes,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('[MTF Runner] Failed to dispatch indicator persistence job', [
                'run_id' => $runId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return string[]
     */
    private function resolveTimeframes(MtfRunnerRequestDto $request): array
    {
        if (\is_string($request->currentTf) && $request->currentTf !== '') {
            return [$request->currentTf];
        }

        // getListTimeframe() exige un profil non nul (cf. MtfValidatorInterface).
        // Sans profil résolu, on saute la projection (best-effort) plutôt que de
        // provoquer un TypeError qui ferait échouer tout le run.
        if (!\is_string($request->profile) || $request->profile === '') {
            return [];
        }

        return $this->mtfValidator->getListTimeframe($request->profile);
    }
}
