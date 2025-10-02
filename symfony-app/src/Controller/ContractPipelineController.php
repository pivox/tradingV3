<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ContractPipelineController extends AbstractController
{
    private const STAGE_FLOW = [
        ['tf' => ContractPipeline::TF_4H,  'label' => 'Analyse 4H'],
        ['tf' => ContractPipeline::TF_1H,  'label' => 'Analyse 1H'],
        ['tf' => ContractPipeline::TF_15M, 'label' => 'Analyse 15M'],
        ['tf' => ContractPipeline::TF_5M,  'label' => 'Analyse 5M'],
        ['tf' => ContractPipeline::TF_1M,  'label' => 'Analyse 1M'],
    ];

    public function __construct(private readonly ContractPipelineRepository $pipelines)
    {
    }

    #[Route('/api/contract-pipeline', name: 'api_contract_pipeline', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $statusFilter = strtolower((string) $request->query->get('status', 'all'));

        $qb = $this->pipelines->createQueryBuilder('p')
            ->innerJoin('p.contract', 'c')->addSelect('c')
            ->leftJoin('p.fromKline', 'kf')->addSelect('kf')
            ->leftJoin('p.toKline', 'kt')->addSelect('kt')
            ->orderBy('p.updatedAt', 'DESC');

        $statuses = $this->mapStatusFilter($statusFilter);
        if ($statuses !== []) {
            $qb->andWhere('p.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        /** @var ContractPipeline[] $pipelines */
        $pipelines = $qb->getQuery()->getResult();

        $payload = array_map(fn (ContractPipeline $pipeline) => $this->serializePipeline($pipeline), $pipelines);

        return new JsonResponse($payload);
    }

    private function mapStatusFilter(string $filter): array
    {
        return match ($filter) {
            'completed' => [
                ContractPipeline::STATUS_VALIDATED,
                ContractPipeline::STATUS_OPENED_LOCKED,
                ContractPipeline::STATUS_ORDER_OPENED,
            ],
            'failed' => [ContractPipeline::STATUS_FAILED],
            'in-progress' => [
                ContractPipeline::STATUS_PENDING,
                ContractPipeline::STATUS_BACK,
            ],
            default => [],
        };
    }

    private function serializePipeline(ContractPipeline $pipeline): array
    {
        $contract = $pipeline->getContract();
        $cardStatus = $this->mapCardStatus($pipeline->getStatus());

        $startAt = $pipeline->getFromKline()?->getTimestamp()
            ?? $pipeline->getLastAttemptAt()
            ?? $pipeline->getUpdatedAt();

        $endAt = $pipeline->getToKline()?->getTimestamp();
        if ($endAt === null && in_array($pipeline->getStatus(), [ContractPipeline::STATUS_VALIDATED, ContractPipeline::STATUS_FAILED], true)) {
            $endAt = $pipeline->getUpdatedAt();
        }

        $stages = $this->buildStageTimeline($pipeline, $cardStatus);

        return [
            'id' => $pipeline->getId(),
            'contract' => [
                'symbol' => $contract->getSymbol(),
                'exchange' => $contract->getExchange()->getName(),
            ],
            'status_raw' => $pipeline->getStatus(),
            'status' => $cardStatus,
            'current_timeframe' => $pipeline->getCurrentTimeframe(),
            'retries' => $pipeline->getRetries(),
            'max_retries' => $pipeline->getMaxRetries(),
            'order_id' => $pipeline->getOrderId(),
            'start_time' => $this->formatDate($startAt),
            'end_time' => $this->formatDate($endAt),
            'duration' => $this->formatDuration($startAt, $endAt),
            'stages' => $stages,
            'signals' => $pipeline->getSignals(),
            'updated_at' => $this->formatDate($pipeline->getUpdatedAt()),
            'last_attempt_at' => $this->formatDate($pipeline->getLastAttemptAt()),
        ];
    }

    private function buildStageTimeline(ContractPipeline $pipeline, string $cardStatus): array
    {
        $currentTf = $pipeline->getCurrentTimeframe();
        $currentIndex = array_search($currentTf, array_column(self::STAGE_FLOW, 'tf'), true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        $timeline = [];
        foreach (self::STAGE_FLOW as $index => $stage) {
            $timeline[] = [
                'timeframe' => $stage['tf'],
                'name' => $stage['label'],
                'status' => $this->determineStageStatus($pipeline, $cardStatus, $index, $currentIndex, $stage['tf']),
            ];
        }

        return $timeline;
    }

    private function determineStageStatus(ContractPipeline $pipeline, string $cardStatus, int $index, int $currentIndex, string $stageTf): string
    {
        if ($cardStatus === 'completed') {
            return 'completed';
        }

        if ($cardStatus === 'failed' && $stageTf === $pipeline->getCurrentTimeframe()) {
            return 'failed';
        }

        if ($index < $currentIndex) {
            return 'completed';
        }

        if ($index === $currentIndex) {
            return $cardStatus === 'failed' ? 'failed' : 'in-progress';
        }

        return 'pending';
    }

    private function mapCardStatus(string $status): string
    {
        return match ($status) {
            ContractPipeline::STATUS_FAILED => 'failed',
            ContractPipeline::STATUS_VALIDATED,
            ContractPipeline::STATUS_OPENED_LOCKED,
            ContractPipeline::STATUS_ORDER_OPENED => 'completed',
            default => 'in-progress',
        };
    }

    private function formatDate(?\DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }

    private function formatDuration(?\DateTimeInterface $start, ?\DateTimeInterface $end): ?string
    {
        if (!$start || !$end) {
            return null;
        }

        $interval = $start->diff($end);
        $parts = [];

        if ($interval->d > 0) {
            $parts[] = sprintf('%dj', $interval->d);
        }
        if ($interval->h > 0) {
            $parts[] = sprintf('%dh', $interval->h);
        }
        if ($interval->i > 0) {
            $parts[] = sprintf('%dm', $interval->i);
        }
        if ($interval->s > 0 && $parts === []) {
            $parts[] = sprintf('%ds', $interval->s);
        }

        return $parts === [] ? '0s' : implode(' ', $parts);
    }
}
