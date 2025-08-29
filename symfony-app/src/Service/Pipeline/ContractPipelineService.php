<?php

namespace App\Service\Pipeline;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ContractPipelineService
{
    public function __construct(
        private readonly ContractPipelineRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * S’assure qu’un contrat existe dans le pipeline en 4h (idempotent).
     * - si absent => crée une ligne en 4h, pending, retries=0, maxRetries=1
     * - si présent => ne modifie pas le timeframe courant
     */
    public function ensureSeeded4h(Contract $contract): ContractPipeline
    {
        $pipe = $this->repo->findOneBy(['contract' => $contract]);
        if ($pipe) {
            return $pipe;
        }

        $pipe = (new ContractPipeline())
            ->setContract($contract)
            ->setCurrentTimeframe(ContractPipeline::TF_4H)
            ->setStatus(ContractPipeline::STATUS_PENDING)
            ->setRetries(0)
            ->setMaxRetries(1)
            ->touchUpdatedAt();

        $this->em->persist($pipe);
        $this->em->flush();

        return $pipe;
    }

    /** Marque une tentative (met à jour lastAttemptAt + updatedAt). */
    public function markAttempt(ContractPipeline $pipe): ContractPipeline
    {
        $pipe->markAttempt();
        $this->em->flush();
        return $pipe;
    }

    /** Incrémente retries et remet en pending. */
    public function incrementRetries(ContractPipeline $pipe): ContractPipeline
    {
        $pipe->incRetries()->setStatus(ContractPipeline::STATUS_PENDING);
        $this->em->flush();
        return $pipe;
    }

    /** Remet retries=0. */
    public function resetRetries(ContractPipeline $pipe): ContractPipeline
    {
        $pipe->resetRetries();
        $this->em->flush();
        return $pipe;
    }

    /** Promotion vers le timeframe suivant avec maxRetries donné. */
    public function promoteTo(ContractPipeline $pipe, string $nextTf, int $maxRetries): ContractPipeline
    {
        $pipe->promoteTo($nextTf, $maxRetries);
        $this->em->flush();
        return $pipe;
    }

    /** Rétrogradation vers le timeframe parent. */
    public function demoteTo(ContractPipeline $pipe, string $parentTf): ContractPipeline
    {
        $pipe->demoteTo($parentTf);
        $this->em->flush();
        return $pipe;
    }

    /**
     * Applique la décision renvoyée par le validateur et transitionne le pipeline.
     *
     * @param array{
     *   valid: bool,
     *   side?: 'LONG'|'SHORT'|null
     * } $decision
     *
     * Règles (par défaut) :
     *  - valid=true :
     *      4h  -> 1h  (maxRetries=3)
     *      1h  -> 15m (maxRetries=3)
     *      15m -> 5m  (maxRetries=2)
     *      5m  -> 1m  (maxRetries=4)
     *      1m  -> ( laisser au caller ouvrir position ; ici on se contente de reset en 4h )
     *  - valid=false :
     *      retries++ ; si retries >= maxRetries -> rétrograde d’un cran (sauf 4h)
     */
    public function applyDecision(ContractPipeline $pipe, string $timeframe, array $decision): ContractPipeline
    {
        $valid = (bool)($decision['valid'] ?? false);

        if ($valid) {
            // Promotion
            switch ($timeframe) {
                case ContractPipeline::TF_4H:
                    $this->promoteTo($pipe, ContractPipeline::TF_1H, 3);
                    break;
                case ContractPipeline::TF_1H:
                    $this->promoteTo($pipe, ContractPipeline::TF_15M, 3);
                    break;
                case ContractPipeline::TF_15M:
                    $this->promoteTo($pipe, ContractPipeline::TF_5M, 2);
                    break;
                case ContractPipeline::TF_5M:
                    $this->promoteTo($pipe, ContractPipeline::TF_1M, 4);
                    break;
                case ContractPipeline::TF_1M:
                    // À ce stade, la position sera ouverte ailleurs (callback),
                    // puis on réamorce en 4h.
                    $this->promoteTo($pipe, ContractPipeline::TF_4H, 1);
                    break;
                default:
                    // Timeframe inconnu : on ne fait rien
                    $pipe->touchUpdatedAt();
                    $this->em->flush();
            }
        } else {
            // Échec
            $pipe->incRetries()->setStatus(ContractPipeline::STATUS_PENDING);

            $parent = match ($timeframe) {
                ContractPipeline::TF_1H  => ContractPipeline::TF_4H,
                ContractPipeline::TF_15M => ContractPipeline::TF_1H,
                ContractPipeline::TF_5M  => ContractPipeline::TF_15M,
                ContractPipeline::TF_1M  => ContractPipeline::TF_5M,
                default => null, // 4h : pas de parent
            };

            if ($parent && $pipe->getRetries() >= $pipe->getMaxRetries()) {
                $pipe->setRetries(0)
                    ->setCurrentTimeframe($parent)
                    ->setStatus(ContractPipeline::STATUS_PENDING)
                    ->touchUpdatedAt();
            }

            $this->em->flush();
        }

        return $pipe;
    }
}
