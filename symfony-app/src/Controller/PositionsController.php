<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Position;
use App\Repository\PositionRepository;
use App\Service\Trading\OpenedLockedSyncService;
use App\Service\Trading\PositionEvaluator;
use App\Service\Trading\PositionFetcher;
use App\Service\Pipeline\MtfPipelineViewService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Expose une vue des positions pour le frontend React.
 * Combine les positions persistées et l'évaluation en direct (BitMart) utilisée par la commande app:evaluate:positions.
 */
final class PositionsController extends AbstractController
{
    private const EXECUTION_LOCK_STATUSES = ['LOCKED_POSITION','LOCKED_ORDER'];

    public function __construct(
        private readonly PositionRepository $positions,
        private readonly PositionEvaluator $evaluator,
        private readonly MtfPipelineViewService $pipelineView,
        private readonly PositionFetcher $positionFetcher,
        private readonly OpenedLockedSyncService $openedLockedSync,
    ) {
    }

    #[Route('/api/positions', name: 'api_positions_list', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $filters = [
            'limit' => (int) $request->query->get('limit', 200),
            'contract' => $request->query->get('contract'),
            'type' => strtolower((string) $request->query->get('type', 'all')),
            'status' => strtolower((string) $request->query->get('status', 'all')),
        ];

        $resultsBySymbol = [];

        // 1) Positions enregistrées en base
        foreach ($this->fetchDatabasePositions($filters) as $positionEntity) {
            $serialized = $this->serializeDatabasePosition($positionEntity);
            $symbol = $serialized['contract']['symbol'];
            $resultsBySymbol[$symbol] = $serialized;
        }

        // 2) Evaluation en direct (même logique que app:evaluate:positions)
        $this->openedLockedSync->sync();
        $livePipelines = array_filter(
            $this->pipelineView->list(null),
            fn(array $row) => $this->isLivePipeline($row)
        );

        foreach ($livePipelines as $pipeline) {
            $symbol = $pipeline['symbol'];

            if ($filters['contract'] && $filters['contract'] !== $symbol) {
                continue;
            }

            $fetched = $this->positionFetcher->fetchPosition($symbol);
            if (!$fetched) {
                continue;
            }

            $live = $this->serializeLivePosition($pipeline, $fetched);
            if ($this->passesFilter($live, $filters)) {
                $resultsBySymbol[$symbol] = $live;
            }
        }

        $results = array_values(array_filter($resultsBySymbol, fn(array $row) => $this->passesFilter($row, $filters)));

        usort($results, static fn(array $a, array $b) => strcmp($b['open_date'] ?? '', $a['open_date'] ?? ''));

        if ($filters['limit'] > 0 && count($results) > $filters['limit']) {
            $results = array_slice($results, 0, $filters['limit']);
        }

        return new JsonResponse($results);
    }

    private function isLivePipeline(array $pipeline): bool
    {
        foreach ($pipeline['eligibility'] ?? [] as $tf => $row) {
            if (in_array($row['status'] ?? '', self::EXECUTION_LOCK_STATUSES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, Position>
     */
    private function fetchDatabasePositions(array $filters): array
    {
        $qb = $this->positions->createQueryBuilder('p')
            ->innerJoin('p.contract', 'c')->addSelect('c')
            ->orderBy('p.openedAt', 'DESC');

        if ($filters['contract']) {
            $qb->andWhere('c.symbol = :contractSymbol')
                ->setParameter('contractSymbol', $filters['contract']);
        }

        if ($filters['type'] !== '' && $filters['type'] !== 'all') {
            $qb->andWhere('p.side = :side')
                ->setParameter('side', strtoupper($filters['type']));
        }

        if ($filters['status'] !== '' && $filters['status'] !== 'all') {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', strtoupper($filters['status']));
        }

        if ($filters['limit'] > 0) {
            $qb->setMaxResults(min($filters['limit'], 500));
        }

        return $qb->getQuery()->getResult();
    }

    private function serializeDatabasePosition(Position $position): array
    {
        $contract = $position->getContract();
        $exchange = $contract->getExchange();
        $contractSize = (float) ($contract->getContractSize() ?? 1.0);

        $qtyContracts = (float) ($position->getQtyContract() ?? 0.0);
        $baseQuantity = $qtyContracts * ($contractSize > 0.0 ? $contractSize : 1.0);

        $entryPrice = $this->toFloat($position->getEntryPrice());
        $markPrice = $this->estimateMarkPrice($position, $contract, $entryPrice);

        $evaluationInput = (object) [
            'side'          => $position->getSide(),
            'quantity'      => $qtyContracts,
            'entryPrice'    => $entryPrice,
            'markPrice'     => $markPrice,
            'leverage'      => $this->toFloat($position->getLeverage()),
            'contractSize'  => $contractSize > 0.0 ? $contractSize : 1.0,
            'stopLoss'      => $this->toFloat($position->getStopLoss()),
            'takeProfit'    => $this->toFloat($position->getTakeProfit()),
            'liqPrice'      => $this->firstNumeric($position->getMeta() ?? [], 'liq_price', 'liqPrice', 'liquidation_price'),
            'openTime'      => $position->getOpenedAt()?->getTimestamp(),
        ];

        $evaluation = $this->evaluator->evaluate($evaluationInput);

        return [
            'id' => $position->getId(),
            'source' => 'database',
            'contract' => [
                'symbol' => $contract->getSymbol(),
                'exchange' => $exchange?->getName(),
            ],
            'type' => strtolower($position->getSide()),
            'status' => strtolower($position->getStatus()),
            'amount_usdt' => $this->toFloat($position->getAmountUsdt()),
            'entry_price' => $entryPrice,
            'exit_price' => $this->firstNumeric($position->getMeta() ?? [], 'exit_price', 'exitPrice') ?? $this->toFloat($position->getTakeProfit()),
            'qty_contract' => $qtyContracts,
            'qty_base' => $baseQuantity,
            'leverage' => $this->toFloat($position->getLeverage()),
            'mark_price' => $markPrice,
            'open_date' => $this->formatDate($position->getOpenedAt() ?? $position->getCreatedAt()),
            'close_date' => $this->formatDate($position->getClosedAt()),
            'stop_loss' => $this->toFloat($position->getStopLoss()),
            'take_profit' => $this->toFloat($position->getTakeProfit()),
            'pnl' => $evaluation['roi_pct'],
            'pnl_usdt' => $evaluation['pnl'],
            'price_change_pct' => $evaluation['price_change_pct'],
            'position_effect_pct' => $evaluation['position_effect_pct'],
            'roi_pct' => $evaluation['roi_pct'],
            'rr_ratio' => $evaluation['rr_ratio'],
            'r_multiple' => $evaluation['r_multiple'],
            'dist_to_sl_pct' => $evaluation['dist_to_sl_pct'],
            'dist_to_tp_pct' => $evaluation['dist_to_tp_pct'],
            'liq_risk_pct' => $evaluation['liq_risk_pct'],
            'time_in_position_s' => $evaluation['time_in_position_s'],
            'time_in_force' => strtolower($position->getTimeInForce()),
            'expires_at' => $this->formatDate($position->getExpiresAt()),
            'external_order_id' => $position->getExternalOrderId(),
            'external_status' => $position->getExternalStatus() ? strtolower($position->getExternalStatus()) : null,
            'last_sync_at' => $this->formatDate($position->getLastSyncAt()),
            'meta' => $position->getMeta(),
            'evaluation' => $evaluation,
            'pipeline' => null,
        ];
    }

    private function serializeLivePosition(array $pipeline, object $position): array
    {
        $contract = $pipeline['contract'];
        $symbol = $contract['symbol'];
        $quantityContracts = (float) ($position->contractsQty ?? $position->quantity ?? 0.0);
        $entryPrice = $this->toFloat($position->entryPrice ?? null);
        $markPrice = $this->toFloat($position->markPrice ?? null);
        $stopLoss = $this->toFloat($position->stopLoss ?? null);
        $takeProfit = $this->toFloat($position->takeProfit ?? null);
        $leverage = $this->toFloat($position->leverage ?? null);
        $margin = $this->toFloat($position->margin ?? null);
        if (($margin === null || $margin == 0.0) && $entryPrice && $leverage) {
            $notional = $quantityContracts * $entryPrice;
            if ($notional > 0 && $leverage > 0) {
                $margin = $notional / $leverage;
            }
        }
        $evaluationInput = (object) [
            'side'          => $position->side ?? null,
            'quantity'      => $quantityContracts,
            'entryPrice'    => $entryPrice,
            'markPrice'     => $markPrice,
            'leverage'      => $leverage,
            'contractSize'  => 1.0,
            'stopLoss'      => $stopLoss,
            'takeProfit'    => $takeProfit,
            'liqPrice'      => $this->toFloat($position->liqPrice ?? null),
            'openTime'      => $position->openTime ?? null,
        ];
        $evaluation = $this->evaluator->evaluate($evaluationInput);
        return [
            'id' => null,
            'source' => 'exchange',
            'contract' => [
                'symbol' => $symbol,
                'exchange' => $contract['exchange'],
            ],
            'type' => strtolower((string) ($position->side ?? 'unknown')),
            'status' => 'open',
            'amount_usdt' => $margin,
            'entry_price' => $entryPrice,
            'exit_price' => $takeProfit,
            'qty_contract' => $quantityContracts,
            'qty_base' => $quantityContracts,
            'leverage' => $leverage,
            'mark_price' => $markPrice,
            'open_date' => $this->formatUnixTimestamp($position->openTime ?? null),
            'close_date' => null,
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'pnl' => $evaluation['roi_pct'],
            'pnl_usdt' => $evaluation['pnl'],
            'price_change_pct' => $evaluation['price_change_pct'],
            'position_effect_pct' => $evaluation['position_effect_pct'],
            'roi_pct' => $evaluation['roi_pct'],
            'rr_ratio' => $evaluation['rr_ratio'],
            'r_multiple' => $evaluation['r_multiple'],
            'dist_to_sl_pct' => $evaluation['dist_to_sl_pct'],
            'dist_to_tp_pct' => $evaluation['dist_to_tp_pct'],
            'liq_risk_pct' => $evaluation['liq_risk_pct'],
            'time_in_position_s' => $evaluation['time_in_position_s'],
            'time_in_force' => $pipeline['current_timeframe'],
            'expires_at' => null,
            'external_order_id' => $pipeline['order_id'],
            'external_status' => $pipeline['card_status'],
            'last_sync_at' => $pipeline['updated_at'],
            'meta' => [
                'raw_position' => $position,
            ],
            'evaluation' => $evaluation,
            'pipeline' => $this->summarizePipeline($pipeline),
        ];
    }

    private function summarizePipeline(array $pipeline): array
    {
        return [
            'status' => $pipeline['card_status'],
            'current_timeframe' => $pipeline['current_timeframe'],
            'retries' => $pipeline['retries_current'],
            'max_retries' => $pipeline['max_retries'],
            'signals' => $pipeline['signals'],
            'eligibility' => $pipeline['eligibility'],
        ];
    }
    private function passesFilter(array $row, array $filters): bool
    {
        if ($filters['type'] !== '' && $filters['type'] !== 'all' && ($row['type'] ?? null) !== $filters['type']) {
            return false;
        }

        if ($filters['status'] !== '' && $filters['status'] !== 'all' && ($row['status'] ?? null) !== $filters['status']) {
            return false;
        }

        return true;
    }

    private function estimateMarkPrice(Position $position, $contract, ?float $entryPrice): ?float
    {
        $meta = $position->getMeta() ?? [];
        $fromMeta = $this->firstNumeric($meta, 'mark_price', 'markPrice', 'price_mark');
        if ($fromMeta !== null) {
            return $fromMeta;
        }

        $contractLastPrice = $contract->getLastPrice();
        if ($contractLastPrice !== null) {
            return (float) $contractLastPrice;
        }

        return $entryPrice;
    }

    private function toFloat(null|int|float|string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function firstNumeric(array $source, string ...$keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function formatDate(?\DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }

    private function formatUnixTimestamp(mixed $timestamp): ?string
    {
        if (!is_numeric($timestamp)) {
            return null;
        }

        $ts = (int) $timestamp;
        if ($ts <= 0) {
            return null;
        }

        if ($ts > 2_000_000_000) {
            $ts = (int) floor($ts / 1000);
        }

        return (new DateTimeImmutable())->setTimestamp($ts)->format(DATE_ATOM);
    }
}
