<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller\Web;

use App\MtfValidator\Repository\MtfLockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MtfLockWebController extends AbstractController
{
    public function __construct(
        private readonly MtfLockRepository $lockRepository,
    ) {
    }

    #[Route('/mtf/locks', name: 'mtf_locks_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('MtfValidator/lock/index.html.twig', [
            'locks' => [],
        ]);
    }

    #[Route('/mtf/locks/data', name: 'mtf_locks_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        $draw = (int)($request->query->get('draw') ?? 0);
        $start = (int)($request->query->get('start') ?? 0);
        $length = (int)($request->query->get('length') ?? 25);
        $searchValue = (string)($request->query->all('search')['value'] ?? '');
        $order = $request->query->all('order');

        $orderCol = 5; // acquired_at desc
        $orderDir = 'desc';
        if (is_array($order) && isset($order[0])) {
            $orderCol = (int)($order[0]['column'] ?? 5);
            $orderDir = strtolower((string)($order[0]['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        }

        $lockKey = $request->query->get('lock_key');
        $status = $request->query->get('status'); // active|released|expired

        $qb = $this->lockRepository->createQueryBuilder('l');

        $total = (int)(clone $qb)->select('COUNT(l.lockKey)')->getQuery()->getSingleScalarResult();

        if (is_string($lockKey) && $lockKey !== '') {
            $qb->andWhere('l.lockKey LIKE :lk')->setParameter('lk', '%' . $lockKey . '%');
        }

        // Status filter implemented post-query because of computed status
        if ($searchValue !== '') {
            $qb->andWhere('l.lockKey LIKE :q OR l.processId LIKE :q OR l.metadata LIKE :q')
               ->setParameter('q', '%' . $searchValue . '%');
        }

        $map = [
            0 => 'l.lockKey',
            1 => 'l.processId',
            5 => 'l.acquiredAt',
            6 => 'l.expiresAt',
        ];
        $qb->orderBy($map[$orderCol] ?? 'l.acquiredAt', $orderDir);
        $qb->setFirstResult($start)->setMaxResults($length);

        $locks = $qb->getQuery()->getResult();

        $rows = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($locks as $l) {
            $expired = $l->getExpiresAt() !== null && $l->getExpiresAt() <= $now;
            $statusVal = $expired ? 'expired' : 'active';
            if (is_string($status) && $status !== '' && $statusVal !== $status) {
                continue; // emulate status filter
            }
            $rows[] = [
                'lock_key' => $l->getLockKey(),
                'process_id' => $l->getProcessId(),
                'status' => $statusVal,
                'duration' => max(0, $now->getTimestamp() - $l->getAcquiredAt()->getTimestamp()),
                'acquired_at' => $l->getAcquiredAt()->format('Y-m-d H:i:s'),
                'expires_at' => $l->getExpiresAt()?->format('Y-m-d H:i:s'),
                'metadata' => $l->getMetadata(),
            ];
        }

        // Filtered count (approximate: count of rows after status filter)
        $filtered = count($rows);

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => array_values($rows),
        ]);
    }
}

