<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Persistence;

use App\MtfValidator\Entity\{MtfRun, MtfRunMetric, MtfRunSymbol};
use App\MtfValidator\Repository\{MtfRunRepository, MtfRunSymbolRepository};
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: RunSinkInterface::class)]
final class DbRunSink implements RunSinkInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MtfRunRepository $runRepo,
        private readonly MtfRunSymbolRepository $symbolRepo,
    ) {}

    public function onRunStart(string $runId, array $meta): void
    {
        try {
            $uuid = Uuid::fromString($runId);
            $run = new MtfRun($uuid);
            $run->setStatus('running')
                ->setDryRun((bool)($meta['dry_run'] ?? false))
                ->setForceRun((bool)($meta['force_run'] ?? false))
                ->setCurrentTf(isset($meta['current_tf']) && is_string($meta['current_tf']) ? $meta['current_tf'] : null)
                ->setWorkers(isset($meta['workers']) ? (int)$meta['workers'] : null)
                ->setUserId(isset($meta['user_id']) && is_string($meta['user_id']) ? $meta['user_id'] : null)
                ->setIpAddress(isset($meta['ip_address']) && is_string($meta['ip_address']) ? $meta['ip_address'] : null)
                ->setOptionsJson($meta['options'] ?? null);

            $this->em->persist($run);
            $this->em->flush();
        } catch (\Throwable) {
            // Non-bloquant: ignorer erreurs de persistance
        }
    }

    public function onSymbolResult(string $runId, array $symbolResult): void
    {
        try {
            $uuid = Uuid::fromString($runId);
            /** @var MtfRun|null $run */
            $run = $this->runRepo->find($uuid);
            if (!$run) { return; }

            $symbol = (string)($symbolResult['symbol'] ?? '');
            if ($symbol === '') { return; }

            $existing = $this->symbolRepo->findOneBy(['run' => $run, 'symbol' => $symbol]);
            if (!$existing) {
                $existing = new MtfRunSymbol($run, $symbol);
                $this->em->persist($existing);
            }
            $existing->setFromArray($symbolResult);

            $this->em->flush();
        } catch (\Throwable) {
        }
    }

    public function onRunCompleted(string $runId, array $summary, array $results): void
    {
        try {
            $uuid = Uuid::fromString($runId);
            /** @var MtfRun|null $run */
            $run = $this->runRepo->find($uuid);
            if (!$run) { return; }

            $run
                ->setStatus((string)($summary['status'] ?? 'completed'))
                ->setExecutionTimeSeconds((float)($summary['execution_time_seconds'] ?? 0.0))
                ->setSymbolsRequested((int)($summary['symbols_requested'] ?? 0))
                ->setSymbolsProcessed((int)($summary['symbols_processed'] ?? 0))
                ->setSymbolsSuccessful((int)($summary['symbols_successful'] ?? 0))
                ->setSymbolsFailed((int)($summary['symbols_failed'] ?? 0))
                ->setSymbolsSkipped((int)($summary['symbols_skipped'] ?? 0))
                ->setSuccessRate((float)($summary['success_rate'] ?? 0.0))
                ->setCurrentTf(isset($summary['current_tf']) ? (string)$summary['current_tf'] : null)
                ->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            $this->em->flush();
        } catch (\Throwable) {
        }
    }

    public function onMetrics(string $runId, array $report): void
    {
        try {
            $uuid = Uuid::fromString($runId);
            /** @var MtfRun|null $run */
            $run = $this->runRepo->find($uuid);
            if (!$run) { return; }

            $metrics = $report['metrics'] ?? [];
            if (!is_array($metrics)) { return; }
            foreach ($metrics as $m) {
                if (!is_array($m)) { continue; }
                $metric = new MtfRunMetric($run, (string)($m['category'] ?? 'unknown'), (string)($m['operation'] ?? 'unknown'));
                $metric
                    ->setSymbol(isset($m['symbol']) && is_string($m['symbol']) ? $m['symbol'] : null)
                    ->setTimeframe(isset($m['timeframe']) && is_string($m['timeframe']) ? $m['timeframe'] : null)
                    ->setCount((int)($m['count'] ?? 0))
                    ->setDuration((float)($m['duration'] ?? 0.0));
                $this->em->persist($metric);
            }
            $this->em->flush();
        } catch (\Throwable) {
        }
    }
}

