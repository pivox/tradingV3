<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

// @tag:mtf-support  Abstraction pour dispatcher les callbacks (Temporal, Messenger, etc.)

interface TemporalDispatcherInterface
{
    /** Envoie une activité/callback d'évaluation pour (symbol, tf, slot). */
    public function dispatchEvaluate(string $symbol, string $tf, \DateTimeImmutable $slot, array $headers = []): void;
}
