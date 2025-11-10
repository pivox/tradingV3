<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller\Api;

use App\MtfValidator\Entity\MtfAudit;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\Provider\Repository\ContractRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/mtf-audits', name: 'api_mtf_audits_')]
class MtfAuditController extends AbstractController
{
    public function __construct(
        private readonly MtfAuditRepository $mtfAuditRepository,
        private readonly ContractRepository $contractRepository,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(500, max(1, (int)$request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;

        $qb = $this->mtfAuditRepository->createQueryBuilder('a');

        $runId = $request->query->get('runId');
        if (is_string($runId) && $runId !== '') {
            try {
                $uuid = Uuid::fromString($runId);
                $qb->andWhere('a.runId = :runId')->setParameter('runId', $uuid);
            } catch (\Throwable) {
                // Ignore invalid UUID filters
            }
        }

        $symbol = $request->query->get('symbol');
        if (is_string($symbol) && $symbol !== '') {
            $qb->andWhere('UPPER(a.symbol) = :symbol')
               ->setParameter('symbol', strtoupper($symbol));
        }

        $stepFilter = $request->query->get('step');
        if (is_string($stepFilter) && $stepFilter !== '') {
            $qb->andWhere('a.step ILIKE :stepFilter')
               ->setParameter('stepFilter', '%' . $stepFilter . '%');
        }

        $verdict = $request->query->get('verdict');
        if (is_string($verdict) && $verdict !== '') {
            $verdict = strtolower($verdict);
            switch ($verdict) {
                case 'passed':
                case 'success':
                case 'valid':
                    $qb->andWhere('(UPPER(a.step) LIKE :verdictSuccess OR UPPER(a.step) = :verdictCompleted)')
                       ->setParameter('verdictSuccess', '%SUCCESS%')
                       ->setParameter('verdictCompleted', 'COMPLETED');
                    break;
                case 'warning':
                case 'partial':
                    $qb->andWhere('UPPER(a.step) LIKE :verdictWarning')
                       ->setParameter('verdictWarning', '%WARNING%');
                    break;
                case 'failed':
                case 'error':
                case 'invalid':
                    $qb->andWhere('(UPPER(a.step) LIKE :verdictFailed OR UPPER(a.step) LIKE :verdictError)')
                       ->setParameter('verdictFailed', '%FAILED%')
                       ->setParameter('verdictError', '%ERROR%');
                    break;
                case 'skipped':
                case 'pending':
                    $qb->andWhere('UPPER(a.step) LIKE :verdictSkipped')
                       ->setParameter('verdictSkipped', '%SKIP%');
                    break;
            }
        }

        $dateFrom = $request->query->get('dateFrom');
        if (is_string($dateFrom) && $dateFrom !== '') {
            try {
                $from = new \DateTimeImmutable($dateFrom, new \DateTimeZone('UTC'));
                $qb->andWhere('a.createdAt >= :dateFrom')->setParameter('dateFrom', $from);
            } catch (\Throwable) {
                // ignore invalid date filter
            }
        }

        $dateTo = $request->query->get('dateTo');
        if (is_string($dateTo) && $dateTo !== '') {
            try {
                $to = new \DateTimeImmutable($dateTo, new \DateTimeZone('UTC'));
                $qb->andWhere('a.createdAt <= :dateTo')->setParameter('dateTo', $to);
            } catch (\Throwable) {
                // ignore invalid date filter
            }
        }

        $sortKey = $request->query->get('sort', 'createdAt');
        $order = strtolower((string)$request->query->get('order', 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $sortMap = [
            'id' => 'a.id',
            'runId' => 'a.runId',
            'symbol' => 'a.symbol',
            'step' => 'a.step',
            'verdict' => 'a.severity',
            'timestamp' => 'a.createdAt',
            'createdAt' => 'a.createdAt',
        ];
        $orderBy = $sortMap[$sortKey] ?? 'a.createdAt';
        $qb->orderBy($orderBy, $order);

        $countQb = clone $qb;
        $total = (int)$countQb->select('COUNT(a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $audits = $qb
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $formatDate = static function (? \DateTimeImmutable $value): ?string {
            return $value?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        };

        $data = array_map(
            static function (MtfAudit $audit) use ($formatDate): array {
                $step = $audit->getStep();
                $verdict = self::deriveVerdict($step);

                return [
                    'id' => $audit->getId(),
                    'runId' => $audit->getRunId()->toString(),
                    'symbol' => $audit->getSymbol(),
                    'step' => $step,
                    'timeframe' => $audit->getTimeframe()?->value,
                    'cause' => $audit->getCause(),
                    'verdict' => $verdict,
                    'payload' => $audit->getDetails(),
                    'timestamp' => $formatDate($audit->getCreatedAt()),
                    'candleTimestamp' => $formatDate($audit->getCandleOpenTs()),
                ];
            },
            $audits
        );

        return $this->json([
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'data' => $data,
        ]);
    }

    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $symbol = $request->query->get('symbol');
        $results = $this->mtfAuditRepository->getLatestValidationSuccessesPerSymbol(
            is_string($symbol) ? $symbol : null
        );

        return $this->json([
            'count' => count($results),
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'data' => $results,
        ]);
    }

    #[Route('/contract/{symbol}', name: 'contract', methods: ['GET'])]
    public function contractDetails(string $symbol): JsonResponse
    {
        $contract = $this->contractRepository->findBySymbol(strtoupper($symbol));
        
        if (!$contract) {
            return $this->json(['error' => 'Contrat non trouvé'], 404);
        }

        $formatTimestamp = static function (?int $ts): ?string {
            if (!$ts) {
                return null;
            }
            // Convertir millisecondes en secondes si nécessaire
            $seconds = $ts > 1000000000000 ? $ts / 1000 : $ts;
            return (new \DateTimeImmutable('@' . (int)$seconds, new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::ATOM);
        };

        return $this->json([
            'id' => $contract->getId(),
            'symbol' => $contract->getSymbol(),
            'name' => $contract->getName(),
            'status' => $contract->getStatus(),
            'product_type' => $contract->getProductType(),
            'base_currency' => $contract->getBaseCurrency(),
            'quote_currency' => $contract->getQuoteCurrency(),
            'open_timestamp' => $contract->getOpenTimestamp(),
            'open_date' => $formatTimestamp($contract->getOpenTimestamp()),
            'expire_timestamp' => $contract->getExpireTimestamp(),
            'expire_date' => $formatTimestamp($contract->getExpireTimestamp()),
            'settle_timestamp' => $contract->getSettleTimestamp(),
            'settle_date' => $formatTimestamp($contract->getSettleTimestamp()),
            'last_price' => $contract->getLastPrice(),
            'index_price' => $contract->getIndexPrice(),
            'index_name' => $contract->getIndexName(),
            'volume_24h' => $contract->getVolume24h(),
            'turnover_24h' => $contract->getTurnover24h(),
            'high_24h' => $contract->getHigh24h(),
            'low_24h' => $contract->getLow24h(),
            'change_24h' => $contract->getChange24h(),
            'contract_size' => $contract->getContractSize(),
            'min_leverage' => $contract->getMinLeverage(),
            'max_leverage' => $contract->getMaxLeverage(),
            'price_precision' => $contract->getPricePrecision(),
            'vol_precision' => $contract->getVolPrecision(),
            'min_size' => $contract->getMinSize(),
            'max_size' => $contract->getMaxSize(),
            'tick_size' => $contract->getTickSize(),
            'multiplier' => $contract->getMultiplier(),
            'min_volume' => $contract->getMinVolume(),
            'max_volume' => $contract->getMaxVolume(),
            'market_max_volume' => $contract->getMarketMaxVolume(),
            'funding_rate' => $contract->getFundingRate(),
            'expected_funding_rate' => $contract->getExpectedFundingRate(),
            'funding_interval_hours' => $contract->getFundingIntervalHours(),
            'open_interest' => $contract->getOpenInterest(),
            'open_interest_value' => $contract->getOpenInterestValue(),
            'delist_time' => $contract->getDelistTime(),
            'delist_date' => $formatTimestamp($contract->getDelistTime()),
            'inserted_at' => $contract->getInsertedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $contract->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    private static function deriveVerdict(string $step): string
    {
        $normalized = strtolower($step);
        return match (true) {
            str_contains($normalized, 'success'),
            str_contains($normalized, 'validated'),
            $normalized === 'completed' => 'SUCCESS',
            str_contains($normalized, 'failed'),
            str_contains($normalized, 'error'),
            str_contains($normalized, 'invalid') => 'FAILED',
            str_contains($normalized, 'warning'),
            str_contains($normalized, 'partial') => 'WARNING',
            str_contains($normalized, 'skip'),
            str_contains($normalized, 'pending') => 'SKIPPED',
            default => 'INFO',
        };
    }
}
