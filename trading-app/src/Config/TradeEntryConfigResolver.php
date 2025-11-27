<?php
declare(strict_types=1);

namespace App\Config;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helper pour récupérer la TradeEntryConfig correspondant au mode demandé.
 */
final class TradeEntryConfigResolver
{
    public function __construct(
        private readonly TradeEntryConfigProvider $provider,
        private readonly TradeEntryModeContext $modeContext,
        #[Autowire(service: 'monolog.logger.positions')] private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Récupère la config pour un mode donné, avec fallback sur le mode actif par défaut.
     */
    public function resolve(?string $mode = null): TradeEntryConfig
    {
        $resolvedMode = $this->modeContext->resolve($mode);

        try {
            return $this->provider->getConfigForMode($resolvedMode);
        } catch (\RuntimeException $e) {
            $fallbackMode = $this->modeContext->resolve(null);
            if ($resolvedMode !== $fallbackMode) {
                $this->logger?->warning('trade_entry_config.resolve_failed', [
                    'mode' => $resolvedMode,
                    'fallback_mode' => $fallbackMode,
                    'error' => $e->getMessage(),
                ]);

                return $this->provider->getConfigForMode($fallbackMode);
            }

            throw $e;
        }
    }

    /**
     * Retourne le mode qui sera effectivement utilisé (mode fourni ou fallback).
     */
    public function resolveMode(?string $mode = null): string
    {
        return $this->modeContext->resolve($mode);
    }

    /**
     * Expose la liste des modes activés.
     *
     * @return array<int, array{name: string, enabled: bool, priority: int}>
     */
    public function getEnabledModes(): array
    {
        return $this->modeContext->getEnabledModes();
    }
}
