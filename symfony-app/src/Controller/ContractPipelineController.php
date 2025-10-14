<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Pipeline\MtfPipelineViewService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ContractPipelineController extends AbstractController
{
    private const STAGE_FLOW = [
        ['tf' => '4h',  'label' => 'Analyse 4H'],
        ['tf' => '1h',  'label' => 'Analyse 1H'],
        ['tf' => '15m', 'label' => 'Analyse 15M'],
        ['tf' => '5m',  'label' => 'Analyse 5M'],
        ['tf' => '1m',  'label' => 'Analyse 1M'],
    ];

    public function __construct(private readonly MtfPipelineViewService $pipelines)
    {
    }

    #[Route('/api/contract-pipeline', name: 'api_contract_pipeline', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $statusFilter = strtolower((string)$request->query->get('status', 'all'));
        $items = $this->pipelines->list($statusFilter === 'all' ? null : $statusFilter);
        $payload = array_map(fn(array $pipeline) => $this->serializePipeline($pipeline), $items);
        return new JsonResponse($payload);
    }

    private function serializePipeline(array $pipeline): array
    {
        $contract = $pipeline['contract'];
        $startAt = $this->findEarliestSlot($pipeline['signals'] ?? []);
        $endAt = $this->findLatestSlot($pipeline['signals'] ?? []);
        $stages = $this->buildStageTimeline($pipeline);

        return [
            'symbol' => $contract['symbol'],
            'contract' => $contract,
            'status_raw' => $pipeline['card_status'],
            'status' => $pipeline['card_status'],
            'current_timeframe' => $pipeline['current_timeframe'],
            'retries' => $pipeline['retries_current'] ?? 0,
            'max_retries' => $pipeline['max_retries'] ?? 0,
            'order_id' => $pipeline['order_id'],
            'start_time' => $startAt,
            'end_time' => $endAt,
            'duration' => $this->computeDuration($startAt, $endAt),
            'stages' => $stages,
            'signals' => $pipeline['signals'],
            'eligibility' => $pipeline['eligibility'],
            'retries_per_tf' => $pipeline['retries'],
            'pending_children' => $pipeline['pending_children'],
            'updated_at' => $pipeline['updated_at'],
            'last_attempt_at' => $pipeline['last_attempt_at'],
        ];
    }

    private function buildStageTimeline(array $pipeline): array
    {
        $currentTf = $pipeline['current_timeframe'];
        $cardStatus = $pipeline['card_status'];
        $timeline = [];
        foreach (self::STAGE_FLOW as $stage) {
            $timeline[] = [
                'timeframe' => $stage['tf'],
                'name' => $stage['label'],
                'status' => $this->determineStageStatus($pipeline, $stage['tf'], $currentTf, $cardStatus),
            ];
        }
        return $timeline;
    }

    private function determineStageStatus(array $pipeline, string $stageTf, string $currentTf, string $cardStatus): string
    {
        $order = array_map(static fn($row) => $row['tf'], self::STAGE_FLOW);
        $indexCurrent = array_search($currentTf, $order, true);
        $indexStage = array_search($stageTf, $order, true);
        if ($indexCurrent === false) {
            $indexCurrent = 0;
        }
        if ($indexStage === false) {
            $indexStage = 0;
        }
        if ($cardStatus === 'completed') {
            return 'completed';
        }
        if ($indexStage < $indexCurrent) {
            $signal = strtoupper($pipeline['signals'][$stageTf]['signal'] ?? 'NONE');
            return $signal !== 'NONE' ? 'completed' : 'pending';
        }
        if ($indexStage === $indexCurrent) {
            return $cardStatus === 'failed' ? 'failed' : 'in-progress';
        }
        return 'pending';
    }

    private function findEarliestSlot(array $signals): ?string
    {
        $earliest = null;
        foreach ($signals as $payload) {
            if (empty($payload['slot_start'])) {
                continue;
            }
            $slot = $payload['slot_start'];
            if (!$slot instanceof DateTimeImmutable) {
                continue;
            }
            if ($earliest === null || $slot < $earliest) {
                $earliest = $slot;
            }
        }
        return $earliest?->format(DateTimeImmutable::ATOM);
    }

    private function findLatestSlot(array $signals): ?string
    {
        $latest = null;
        foreach ($signals as $payload) {
            if (empty($payload['slot_start'])) {
                continue;
            }
            $slot = $payload['slot_start'];
            if (!$slot instanceof DateTimeImmutable) {
                continue;
            }
            if ($latest === null || $slot > $latest) {
                $latest = $slot;
            }
        }
        return $latest?->format(DateTimeImmutable::ATOM);
    }

    private function computeDuration(?string $start, ?string $end): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }
        $startDt = new DateTimeImmutable($start);
        $endDt = new DateTimeImmutable($end);
        $interval = $startDt->diff($endDt);
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
        if ($parts === []) {
            $parts[] = sprintf('%ds', $interval->s);
        }
        return implode(' ', $parts);
    }
}
