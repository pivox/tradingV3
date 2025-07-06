<?php

namespace App\DataProvider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Contract;
use App\Entity\Exchange;
use App\Entity\Kline;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fournisseur personnalisé pour filtrer les Klines selon l'exchange passé dans l'URL.
 */
class KlineProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []):  object|array|null
    {
//        if (!isset($uriVariables['exchange'])) {
//            throw new NotFoundHttpException('Paramètre {exchange} manquant.');
//        }
        $exchangeName = $uriVariables['exchange'] ?? 'bitmart';
        $filters = $context['filters'] ?? [];
        $symbol = $filters['symbol'] ?? null;
        $interval = $filters['interval'] ?? null;

        if (!$symbol || !$interval) {
            return []; // paramètres requis manquants
        }

        // Convertir l'intervalle (1m, 15m, 1h, etc.) en secondes
        $intervalMap = [
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '1h' => 60,
            '4h' => 240,
            '1d' => 1440,
        ];

        $step = $intervalMap[$interval] ?? null;
        if (!$step) {
            return []; // intervalle non reconnu
        }

        $exchange = $this->entityManager->getRepository(Exchange::class)
            ->findOneBy(['name' => $exchangeName]);

        if (!$exchange) {
            throw new NotFoundHttpException("Exchange '$exchangeName' non trouvé.");
        }

        // Récupération du contrat unique
        $contract = $this->entityManager->getRepository(Contract::class)
            ->findOneBy([
                'symbol' => $symbol,
                'exchange' => $exchange
            ]);


        if (!$contract) {
            return []; // contrat non trouvé pour cet exchange
        }

        $query = $this->entityManager->getRepository(Kline::class)
            ->createQueryBuilder('k')
            ->where('k.contract = :contract')
            ->andWhere('k.step = :step')
            ->orderBy('k.timestamp', 'DESC')
            ->setMaxResults(500) // ✅ sécurité mémoire
            ->setParameter('contract', $contract->getSymbol())
            ->setParameter('step', $step)
            ->getQuery();
//        print($query->getSQL());dd(
//            array_map(fn($item) => $item->getValue(), $query->getParameters()->toArray())
//    ,
//    $query->getResult());

        return $query
            ->getResult();
    }
}
