<?php

declare(strict_types=1);

namespace App\Service\Bitmart;

use App\Entity\BlacklistedContract;
use App\Repository\BlacklistedContractRepository;
use Doctrine\ORM\EntityManagerInterface;

final class BlacklistService
{
    public function __construct(
        private readonly BlacklistedContractRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    public function isBlacklisted(string $symbol): bool
    {
        return $this->repo->isBlacklisted($symbol);
    }

    /** À appeler quand tu détectes “pas de réponse pendant ≥10min”. */
    public function registerNoResponse(string $symbol): void
    {
        $symbol = strtoupper($symbol);
        $b = $this->repo->findOneBy(['symbol' => $symbol]);
        if (!$b) {
            $b = new BlacklistedContract($symbol, 'no_response');
            $this->em->persist($b);
        }
        $b->registerNoResponse();
        $this->em->flush();
    }

    /** À appeler dès qu’une réponse valide arrive pour ce symbole. */
    public function registerSuccess(string $symbol): void
    {
        $symbol = strtoupper($symbol);
        $b = $this->repo->findOneBy(['symbol' => $symbol]);
        if ($b) {
            $b->resetNoResponse();
            $this->em->flush();
        }
    }
}
