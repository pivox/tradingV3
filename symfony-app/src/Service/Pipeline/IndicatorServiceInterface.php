<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

// @tag:mtf-core  Interface de calcul des indicateurs

interface IndicatorServiceInterface
{
    /**
     * Évalue un symbole/TF à un slot donné.
     * @return array{passed:bool, side:string, score: ?float, meta: array}
     */
    public function evaluate(string $symbol, string $tf, \DateTimeImmutable $slot): array;
}
