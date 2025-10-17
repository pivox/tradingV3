<?php
namespace App\Service\MTF;

use App\Repository\MtfPlanRepository;

final class MtfContractSelector
{
    public function __construct(private MtfPlanRepository $repo) {}

    /** Renvoie la liste des symboles à traiter pour le TF courant (tri alphabétique). */
    public function symbolsFor(string $tf): array
    {
        return $this->repo->findEnabledSymbolsFor($tf);
    }

    /** Parents “standards” en fonction du TF. */
    public function standardParents(string $tf): array
    {
        return match ($tf) {
            '4h'  => [],
            '1h'  => ['4h'],
            '15m' => ['1h','4h'],
            '5m'  => ['15m','1h','4h'],
            '1m'  => ['5m','15m','1h','4h'],
            default => [],
        };
    }
}
