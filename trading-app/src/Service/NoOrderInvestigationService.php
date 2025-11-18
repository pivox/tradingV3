<?php

declare(strict_types=1);

namespace App\Service;

use App\MtfValidator\Repository\MtfAuditRepository;
use App\Provider\LogProvider;

final class NoOrderInvestigationService
{
    public function __construct(
        private readonly LogProvider $logProvider,
        private readonly MtfAuditRepository $mtfAuditRepository,
    ) {
    }

    /**
     * @param list<string> $symbols
     * @return array<string, NoOrderInvestigationResult>
     */
    public function investigate(array $symbols, \DateTimeImmutable $since, int $maxLogFiles): array
    {
        $logFiles = $this->logProvider->getRecentPositionLogFiles($maxLogFiles);
        $results = [];
        foreach ($symbols as $symbol) {
            $results[$symbol] = $this->investigateSymbol($symbol, $since, $logFiles);
        }

        return $results;
    }

    /**
     * @param list<string>|null $logFiles
     */
    public function investigateSymbol(string $symbol, \DateTimeImmutable $since, ?array $logFiles = null): NoOrderInvestigationResult
    {
        $files = $logFiles ?? $this->logProvider->getRecentPositionLogFiles(2);
        $scan = $this->logProvider->scanPositionsLogsForSymbol($symbol, $since, $files);
        if ($scan->status !== null) {
            return new NoOrderInvestigationResult($symbol, $scan->status, $scan->reason, $scan->details);
        }

        $audit = $this->findLastBlockingAudit($symbol, $since);
        if ($audit !== null) {
            return new NoOrderInvestigationResult($symbol, 'mtf_not_ready', $audit['step'] ?? 'MTF_BLOCKER', [
                'cause' => $audit['cause'] ?? null,
                'timeframe' => $audit['timeframe'] ?? ($audit['details']['timeframe'] ?? null),
                'kline_time' => $audit['details']['kline_time'] ?? null,
                'created_at' => $audit['created_at'] ?? null,
            ]);
        }

        return new NoOrderInvestigationResult($symbol, 'unknown', 'no_traces', []);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findLastBlockingAudit(string $symbol, \DateTimeImmutable $since): ?array
    {
        try {
            $qb = $this->mtfAuditRepository->createQueryBuilder('m');
            $qb
                ->where('m.symbol = :symbol')
                ->andWhere('m.createdAt >= :since')
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->like('m.step', ':failed'),
                    $qb->expr()->eq('m.step', ':alignment'),
                    $qb->expr()->eq('m.step', ':kswitch'),
                ))
                ->setParameter('symbol', $symbol)
                ->setParameter('since', $since)
                ->setParameter('failed', '%VALIDATION_FAILED%')
                ->setParameter('alignment', 'ALIGNMENT_FAILED')
                ->setParameter('kswitch', 'KILL_SWITCH_OFF')
                ->orderBy('m.createdAt', 'DESC')
                ->addOrderBy('m.id', 'DESC')
                ->setMaxResults(1);

            $row = $qb->getQuery()->getOneOrNullResult();
            if ($row === null) {
                return null;
            }

            $get = static function (string $prop) use ($row) {
                $method = 'get' . ucfirst($prop);
                return method_exists($row, $method) ? $row->$method() : null;
            };

            $details = $get('details') ?? [];

            return [
                'id' => $get('id'),
                'symbol' => $get('symbol'),
                'step' => $get('step'),
                'cause' => $get('cause'),
                'details' => is_array($details) ? $details : [],
                'timeframe' => ($tf = $get('timeframe')) ? (string) $tf->value : null,
                'created_at' => ($dt = $get('createdAt')) ? $dt->format('Y-m-d H:i:sP') : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
