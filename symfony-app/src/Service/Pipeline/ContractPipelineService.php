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
    public function markAttempt(ContractPipeline $pipe, ?string $meta = null): ContractPipeline
    {
        $pipe->markAttempt($meta);
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
    public function applyDecision(ContractPipeline $pipe, string $timeframe, ?string $metaForPipeLine = null): array
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

        // Nouvelle règle stricte d'alignement pour l'entrée :
        // - Sur 5m: 4h,1h,15m,5m doivent exister et avoir le même side LONG/SHORT
        // - Sur 1m: 4h,1h,15m,5m,1m doivent exister et avoir le même side LONG/SHORT
        $canEnter = false;
        $getSide = static function(array $sig, string $tf): ?string {
            return isset($sig[$tf]['signal']) ? strtoupper((string)$sig[$tf]['signal']) : null;
        };
        $side4h  = $getSide($signals, ContractPipeline::TF_4H);
        $side1h  = $getSide($signals, ContractPipeline::TF_1H);
        $side15m = $getSide($signals, ContractPipeline::TF_15M);
        $side5m  = $getSide($signals, ContractPipeline::TF_5M);
        $side1m  = $getSide($signals, ContractPipeline::TF_1M);

        $allLongShort = static function(array $arr): bool {
            foreach ($arr as $s) { if (!in_array($s, ['LONG','SHORT'], true)) return false; } return true; };

        if ($timeframe === ContractPipeline::TF_5M) {
            if ($allLongShort([$side4h,$side1h,$side15m,$side5m]) && $side4h === $side1h && $side4h === $side15m && $side4h === $side5m) {
                $canEnter = true;
            }
        } elseif ($timeframe === ContractPipeline::TF_1M) {
            if ($allLongShort([$side4h,$side1h,$side15m,$side5m,$side1m]) && $side4h === $side1h && $side4h === $side15m && $side4h === $side5m && $side4h === $side1m) {
                $canEnter = true;
            }
        }

        if (in_array($timeframe, ['1m']) && $valid) {
            $pipe->setStatus(ContractPipeline::STATUS_VALIDATED);
        }
        if ($metaForPipeLine == ContractPipeline::DONT_INC_DEC_DEL) {
            return [$valid, $canEnter];
        }

        if ($valid) {
            switch ($timeframe) {
                case ContractPipeline::TF_4H:
                    $this->promoteTo($pipe, ContractPipeline::TF_1H, 3); break;
                case ContractPipeline::TF_1H:
                    $this->promoteTo($pipe, ContractPipeline::TF_15M, 3); break;
                case ContractPipeline::TF_15M:
                    $this->promoteTo($pipe, ContractPipeline::TF_5M, 2); break;
                case ContractPipeline::TF_5M:
                    $this->promoteTo($pipe, ContractPipeline::TF_1M, 4); break;
                case ContractPipeline::TF_1M:
                    $this->promoteTo($pipe, ContractPipeline::TF_4H, 1); break;
                default:
                    $pipe->touchUpdatedAt();
                    $this->em->flush();
                    $this->em->clear();
            }
        } else {
            $pipe->incRetries()->setStatus(ContractPipeline::STATUS_PENDING);
            $signals = $pipe->getSignals() ?? [];
            foreach ($signals as $tf => $signal) {
                if (!in_array($tf, $relevant)) { unset($signals[$tf]); }
            }
            $pipe->setSignals($signals);
            $parent = match ($timeframe) {
                ContractPipeline::TF_1H  => ContractPipeline::TF_4H,
                ContractPipeline::TF_15M => ContractPipeline::TF_1H,
                ContractPipeline::TF_5M  => ContractPipeline::TF_15M,
                ContractPipeline::TF_1M  => ContractPipeline::TF_5M,
                default => null,
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
    }
}
