<?php

namespace App\Service\Pipeline;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use App\Repository\ContractRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ContractPipelineService
{
    public function __construct(
        private readonly ContractPipelineRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly ContractRepository $contractRepo,
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
        $contract = $pipe->getContract();
        $contract = $this->contractRepo->findOneBy(['symbol' => $contract->getSymbol()]);
        $pipe->setContract($contract);
        $this->em->flush();
        return $pipe;
    }

    /** Incrémente retries et remet en pending. */
    public function incrementRetries(ContractPipeline $pipe): ContractPipeline
    {
        $pipe->incRetries()->setStatus(ContractPipeline::STATUS_PENDING);
        $contract = $pipe->getContract();
        $contract = $this->contractRepo->findOneBy(['symbol' => $contract->getSymbol()]);
        $pipe->setContract($contract);
        $this->em->flush();
        $this->em->clear();
        return $pipe;
    }

    /** Remet retries=0. */
    public function resetRetries(ContractPipeline $pipe): ContractPipeline
    {
        $pipe->resetRetries();
        $contract = $pipe->getContract();
        $contract = $this->contractRepo->findOneBy(['symbol' => $contract->getSymbol()]);
        $pipe->setContract($contract);
        $this->em->flush();
        return $pipe;
    }

    /** Promotion vers le timeframe suivant avec maxRetries donné. */
    public function promoteTo(ContractPipeline $pipe, string $nextTf, int $maxRetries): ContractPipeline
    {
        if ($pipe->getStatus() === ContractPipeline::STATUS_VALIDATED) {
            return $pipe;
        }
        $pipe->promoteTo($nextTf, $maxRetries);
        $contract = $pipe->getContract();
        $contract = $this->contractRepo->findOneBy(['symbol' => $contract->getSymbol()]);
        $pipe->setContract($contract);
        $this->em->flush();
        return $pipe;
    }

    /** Rétrogradation vers le timeframe parent. */
    public function demoteTo(ContractPipeline $pipe, string $parentTf): ContractPipeline
    {
        $pipe->demoteTo($parentTf);
        $contract = $pipe->getContract();
        $contract = $this->contractRepo->findOneBy(['symbol' => $contract->getSymbol()]);
        $pipe->setContract($contract);
        $this->em->flush();
        return $pipe;
    }

    /**
     * Applique la décision renvoyée par le validateur et transitionne le pipeline.
     *
     * @param ContractPipeline $pipe
     * @param string $timeframe
     * @return array
     */
    public function applyDecision(ContractPipeline $pipe, string $timeframe): array
    {
        $signals   = $pipe->getSignals() ?? [];
        $relevant = match ($timeframe) {
            ContractPipeline::TF_4H  => [ContractPipeline::TF_4H],
            ContractPipeline::TF_1H  => [ContractPipeline::TF_4H, ContractPipeline::TF_1H],
            ContractPipeline::TF_15M => [ContractPipeline::TF_4H, ContractPipeline::TF_1H, ContractPipeline::TF_15M],
            ContractPipeline::TF_5M  => [ContractPipeline::TF_4H, ContractPipeline::TF_1H, ContractPipeline::TF_15M, ContractPipeline::TF_5M],
            ContractPipeline::TF_1M  => [ContractPipeline::TF_4H, ContractPipeline::TF_1H, ContractPipeline::TF_15M, ContractPipeline::TF_5M, ContractPipeline::TF_1M],
            default => array_keys($signals),
        };

        $filtered = array_intersect_key($signals, array_flip($relevant));
        $sides    = array_map(fn(array $s) => $s['signal'] ?? 'NONE', $filtered);
        $sides    = array_values(array_unique($sides));
        if (count($sides) > 1 || in_array('NONE', $sides, true) || !in_array($sides[0], ['LONG', 'SHORT'])) {
            $valid = false;
        } else {
            $valid = true;
        }
            // --- Nouveau : calcul can_enter (sans toucher au reste)
            // Règle : 4h et 1h doivent être valides et du même side,
            // et au moins un des 3 TF d'exécution (15m/5m/1m) doit avoir ce même side.
        $canEnter = false;
        $side4h = $signals[ContractPipeline::TF_4H]['signal'] ?? null;
        $side1h = $signals[ContractPipeline::TF_1H]['signal'] ?? null;
        if (in_array($side4h, ['LONG','SHORT'], true) && $side4h === $side1h) {
            foreach ([ContractPipeline::TF_15M, ContractPipeline::TF_5M, ContractPipeline::TF_1M] as $execTf) {
                $sideExec = $signals[$execTf]['signal'] ?? null;
                if ($sideExec === $side4h) { $canEnter = true; break; }
            }
        }

        if (in_array($timeframe, ['1m']) && $valid) {
            $pipe->setStatus(ContractPipeline::STATUS_VALIDATED);
        }

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
                    $this->em->clear();
            }
        } else {
            // Échec
            $pipe->incRetries()->setStatus(ContractPipeline::STATUS_PENDING);
            $signals = $pipe->getSignals() ?? [];
            foreach ($signals as $tf => $signal) {
                if (!in_array($tf, $relevant)) {
                    unset($signals[$tf]);
                }
            }
            $pipe->setSignals($signals);

            $parent = match ($timeframe) {
                ContractPipeline::TF_1H  => ContractPipeline::TF_4H,
                ContractPipeline::TF_15M => ContractPipeline::TF_1H,
                ContractPipeline::TF_5M  => ContractPipeline::TF_15M,
                ContractPipeline::TF_1M  => ContractPipeline::TF_5M,
                default => null, // 4h : pas de parent
            };


            if ($parent && $pipe->getRetries() >= $pipe->getMaxRetries()) {
                $signals = $pipe->getSignals() ?? [];
                unset($signals[$timeframe]);
                $pipe->setSignals($signals)
                    ->setRetries(0)
                    ->setCurrentTimeframe($parent)
                    ->setStatus(ContractPipeline::STATUS_PENDING)
                    ->touchUpdatedAt();
            }
            $contract = $pipe->getContract();
            $contract = $this->contractRepo->findOneBy(['symbol' => $contract->getSymbol()]);
            $pipe->setContract($contract);

            $this->em->flush();
            $this->em->clear();
        }
        return [$valid, $canEnter];
        //return ['valid' => $valid, 'can_enter' => $canEnter];
    }
}
