<?php
// src/Service/ContractSignalWriter.php
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
    )
    {
    }

    /**
     * Enregistre/Met à jour les signaux pour un TF donné + statut (validated/failed).
     * Persiste dans tous les cas (succès/échec).
     *
     * @param Contract $contract
     * @param string $tf ex: '4h'
     * @param array $signals ex: ['ema50'=>..., 'ema200'=>..., 'adx14'=>..., 'ichimoku'=>[...], 'signal'=>'LONG|NONE']
     */
    public function saveAttempt(Contract $contract, string $tf, array $signals, bool $flush = true): ?ContractPipeline
    {
        $pipeline = $this->repo->findOneBy(['contract' => $contract]);

        if (!$pipeline) {
            if ($tf === ContractPipeline::TF_4H) {
                // Crée une nouvelle ligne en 4h si elle n'existe pas déjà
                $pipeline = (new ContractPipeline())->setContract($contract);
            } else {
                return null;
            }
        }

        // Détermine le statut selon la clé 'signal'
        $finalSignal = strtoupper((string)($signals['signal'] ?? 'NONE'));
        $status = $finalSignal === 'NONE'
            ? ContractPipeline::STATUS_FAILED
            : ContractPipeline::STATUS_VALIDATED;

        // Merge des signaux pour le TF, datation et gestion des retries
        $pipeline->addOrMergeSignal($tf, $signals)
            ->setCurrentTimeframe($tf)
            ->setStatus($status)
            ->markAttempt();

        if ($status === ContractPipeline::STATUS_FAILED) {
            $pipeline->incRetries();
        } else {
            $pipeline->resetRetries();
        }

        $this->em->persist($pipeline);
        if ($flush) {
            $this->em->flush();
        }
// NB: on ne flush pas ici si on veut batcher côté contrôleur
        return $pipeline;
    }
}
