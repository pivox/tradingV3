<?php

declare(strict_types=1);

namespace App\Signal\Provider;

use App\Contract\Signal\SignalMainProviderInterface;
use App\Contract\Signal\SignalServiceInterface;
use App\Contract\Signal\SignalValidationServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Façade d'accès aux services du domaine Signal.
 */
#[AsAlias(id: SignalMainProviderInterface::class)]
final readonly class SignalMainProvider implements SignalMainProviderInterface
{
    /**
     * @var list<SignalServiceInterface>
     */
    private array $timeframeServices;

    /**
     * @param iterable<SignalServiceInterface> $timeframeServices
     */
    public function __construct(
        #[TaggedIterator('app.signal.timeframe')]
        iterable $timeframeServices,
        private SignalValidationServiceInterface $validationService,
    ) {
        $this->timeframeServices = [];
        foreach ($timeframeServices as $service) {
            if ($service instanceof SignalServiceInterface) {
                $this->timeframeServices[] = $service;
            }
        }
    }

    public function getTimeframeServices(): iterable
    {
        return $this->timeframeServices;
    }

    public function getSignalService(string $timeframe): ?SignalServiceInterface
    {
        $tf = strtolower($timeframe);
        foreach ($this->timeframeServices as $service) {
            if ($service->supportsTimeframe($tf)) {
                return $service;
            }
        }

        return null;
    }

    public function getValidationService(): SignalValidationServiceInterface
    {
        return $this->validationService;
    }

}
