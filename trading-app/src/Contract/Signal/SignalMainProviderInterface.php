<?php

declare(strict_types=1);

namespace App\Contract\Signal;

/**
 * Façade d'accès aux services du domaine Signal.
 */
interface SignalMainProviderInterface
{
    /**
     * @return iterable<SignalServiceInterface>
     */
    public function getTimeframeServices(): iterable;

    public function getSignalService(string $timeframe): ?SignalServiceInterface;

    public function getValidationService(): SignalValidationServiceInterface;
}
