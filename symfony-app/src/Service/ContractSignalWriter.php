<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use Doctrine\ORM\EntityManagerInterface;

class ContractSignalWriter
{
    public function __construct(
        private readonly ContractPipelineRepository $repo,
        private readonly EntityManagerInterface     $em,
    ) {}

    /**
     * Enregistre/Met à jour les signaux pour un TF donné (retry-friendly).
     * On ne stocke que:
     *  - signals[<tf>] (bloc TF courant),
     *  - final (['signal' => ...]),
     *  - status ('PENDING'|'VALIDATED'|'FAILED')
     */
    public function saveAttempt(Contract $contract, string $tf, array $signals, bool $flush = true): ?ContractPipeline
    {
        $pipeline = $this->repo->findOneBy(['contract' => $contract]);

        if (!$pipeline) {
            if ($tf === ContractPipeline::TF_4H) {
                $pipeline = (new ContractPipeline())->setContract($contract);
            } else {
                return null; // pas de ligne 4h encore: on attend 4h pour l’amorcer
            }
        }

        // Lire final.signal & status depuis la charge reçue
        $finalSignal = strtoupper((string)($signals['final']['signal'] ?? 'NONE'));
        $status      = strtoupper((string)($signals['status'] ?? 'FAILED'));

        // Merge seulement le TF courant
        $toStore = $signals[$tf] ?? [];
        $pipeline->addOrMergeSignal($tf, $toStore)
            ->setCurrentTimeframe($tf)
            ->setStatus($status)
            ->markAttempt();

        if ($status === ContractPipeline::STATUS_FAILED) {
            $pipeline->incRetries();
        }
//        else {
//            $pipeline->resetRetries();
//        }

        $this->em->persist($pipeline);
        if ($flush) {
            $this->em->flush();
        }
        return $pipeline;
    }
}
