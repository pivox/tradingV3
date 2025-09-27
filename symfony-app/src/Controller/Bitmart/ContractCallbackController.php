<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Repository\ContractPipelineRepository;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\ContractPersister;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Callbacks pour Bitmart :
 * - Rafraîchissement de la liste des contrats
 * - Cron périodiques (1m, 5m, 15m, 1h, 4h)
 */
class ContractCallbackController extends AbstractController
{
    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
    )
    {
    }

    #[Route('/api/callback/bitmart/fetch-all-contract', name: 'bitmart_contracts_callback', methods: ['POST'])]
    public function fetchAllContracts(
        EntityManagerInterface $em,
        ContractPersister $persister,
        BitmartFetcher $bitmartFetcher,
        ContractPipelineService $pipelineService,
        LoggerInterface $logger,
    ): JsonResponse {
        $startedAt = microtime(true);

        // 1) Fetch côté API
        $contracts = $bitmartFetcher->fetchContracts();
        $logger->info(sprintf('Fetched %d contracts from Bitmart', \count($contracts)));

        $persisted = 0;
        $seeded = 0;
        $errors = [];

        // 2) Persistance en transaction
        $em->beginTransaction();
        try {
            // 2.a Persist SANS flush (lot)
            foreach ($contracts as $dto) {
                try {
                    $persister->persistFromDto($dto, 'bitmart');
                    $persisted++;
                } catch (\Throwable $e) {
                    // On logge et on continue ; on seedera pas ce symbole
                    $symbol = property_exists($dto, 'symbol') ? $dto->symbol : 'unknown';
                    $errors[] = ['symbol' => $symbol, 'stage' => 'persist', 'error' => $e->getMessage()];
                    $logger->error(sprintf('[Persist][%s] %s', $symbol, $e->getMessage()), ['exception' => $e]);
                }
            }

            // 2.b Flush unique
            try {
                $persister->flush();
            } catch (\Throwable $e) {
                // Échec du flush global : on rollback et on sort
                $em->rollback();
                $logger->critical('[Flush] Global flush failed: '.$e->getMessage(), ['exception' => $e]);

                $duration = round((microtime(true) - $startedAt) * 1000);
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Global flush failed',
                    'persist_attempted' => $persisted,
                    'seeded_pipeline_4h' => 0,
                    'errors' => $errors,
                    'duration_ms' => $duration,
                ], 500);
            }

            // 2.c Commit : les contrats sont maintenant écrits
            $em->commit();
        } catch (\Throwable $e) {
            // En cas d’exception non prévue : rollback global
            $em->rollback();
            $logger->critical('[Transaction] Uncaught exception: '.$e->getMessage(), ['exception' => $e]);

            $duration = round((microtime(true) - $startedAt) * 1000);
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Transaction failed',
                'persist_attempted' => $persisted,
                'seeded_pipeline_4h' => 0,
                'errors' => $errors,
                'duration_ms' => $duration,
            ], 500);
        }

        // 3) Seeding 4h APRÈS persistance (hors transaction)
        //    On refait un tour pour appeler ensureSeeded4h uniquement pour les contrats qui ont été persistés.
        //    Comme fetchContracts() renvoie la même liste, on tente de seed chaque DTO à nouveau
        //    mais on ignore ceux qui avaient échoué en persistance (listés dans $errors[stage=persist]).
        $failedSymbols = array_column(array_filter($errors, fn($e) => $e['stage'] === 'persist'), 'symbol');

        foreach ($contracts as $dto) {
            $symbol = property_exists($dto, 'symbol') ? $dto->symbol : null;
            if ($symbol === null || \in_array($symbol, $failedSymbols, true)) {
                continue; // pas persisté ⇒ pas de seed
            }

            try {
                // On recharge l’entité persistée par son ID (symbol)
                /** @var Contract|null $contract */
                $contract = $em->getRepository(Contract::class)->find($symbol);
                if ($contract === null) {
                    // Par sécurité : si introuvable, on log et on continue
                    $errors[] = ['symbol' => $symbol, 'stage' => 'seed_lookup', 'error' => 'Contract not found after flush'];
                    $logger->warning(sprintf('[Seed][%s] Contract not found after flush', $symbol));
                    continue;
                }

                $pipelineService->ensureSeeded4h($contract);
                $seeded++;
            } catch (\Throwable $e) {
                $errors[] = ['symbol' => $symbol, 'stage' => 'seed', 'error' => $e->getMessage()];
                $logger->error(sprintf('[Seed][%s] %s', $symbol, $e->getMessage()), ['exception' => $e]);
            }
        }

        $duration = round((microtime(true) - $startedAt) * 1000);
        $logger->info(sprintf('Persisted=%d, Seeded4h=%d, Duration=%dms, Errors=%d',
            $persisted, $seeded, $duration, \count($errors)
        ));

        return new JsonResponse([
            'status'               => 'ok',
            'fetched_contracts'    => \count($contracts),
            'persisted_contracts'  => $persisted,
            'seeded_pipeline_4h'   => $seeded,
            'errors'               => $errors,   // liste détaillée par symbole/stage
            'duration_ms'          => $duration,
        ]);
    }


}
