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

        // Préparation des signaux existants (parents) AVANT d'appliquer la logique de validation du TF courant
        $existing = $pipeline->getSignals() ?? [];
        $curSignal = strtoupper((string)($signals[$tf]['signal'] ?? ''));
        $isDirectional = in_array($curSignal, ['LONG','SHORT'], true);
        $sig4h  = strtoupper((string)($existing[ContractPipeline::TF_4H]['signal']  ?? '')); $is4hDir  = in_array($sig4h,  ['LONG','SHORT'], true);
        $sig1h  = strtoupper((string)($existing[ContractPipeline::TF_1H]['signal']  ?? '')); $is1hDir  = in_array($sig1h,  ['LONG','SHORT'], true);
        $sig15m = strtoupper((string)($existing[ContractPipeline::TF_15M]['signal'] ?? '')); $is15mDir = in_array($sig15m, ['LONG','SHORT'], true);
        $sig5m  = strtoupper((string)($existing[ContractPipeline::TF_5M]['signal']  ?? '')); $is5mDir  = in_array($sig5m,  ['LONG','SHORT'], true);

        switch ($tf) {
            case ContractPipeline::TF_4H:
                if ($isDirectional) {
                    $pipeline->setIsValid4h(true);
                }
                break;

            case ContractPipeline::TF_1H:
                // 1h valide si 4h directionnel et même direction
                if ($isDirectional && $is4hDir && $curSignal === $sig4h) {
                    $pipeline->setIsValid1h(true);
                }
                break;

            case ContractPipeline::TF_15M:
                // 15m valide si 4h directionnel et même direction
                if ($isDirectional && $is4hDir && $curSignal === $sig4h) {
                    $pipeline->setIsValid15m(true);
                }
                break;

            case ContractPipeline::TF_5M:
                // 5m valide si 4h directionnel et même direction
                if ($isDirectional && $is4hDir && $curSignal === $sig4h) {
                    $pipeline->setIsValid5m(true);
                }
                break;

            case ContractPipeline::TF_1M:
                // 1m valide si 4h directionnel et même direction
                if ($isDirectional && $is4hDir && $curSignal === $sig4h) {
                    $pipeline->setIsValid1m(true);
                }
                break;

            default:
                throw new \InvalidArgumentException('ContractSignalWriter::saveAttempt: TF invalide: ' . $tf);
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
        // else { $pipeline->resetRetries(); } // conservé commenté

        $this->em->persist($pipeline);
        if ($flush) {
            $this->em->flush();
        }
        return $pipeline;
    }
}
