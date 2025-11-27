<?php
declare(strict_types=1);

namespace App\Config;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Centralise la résolution du mode actif pour TradeEntry.
 * Utilise la configuration des modes (regular/scalper/...) exposée par TradeEntryConfigProvider.
 */
final class TradeEntryModeContext
{
    public function __construct(
        private readonly TradeEntryConfigProvider $configProvider,
        #[Autowire(param: 'app.trade_entry_default_mode')] private readonly string $explicitDefault = 'regular',
        #[Autowire(service: 'monolog.logger.positions')] private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Retourne le mode à utiliser (paramètre fourni ou mode actif par défaut).
     */
    public function resolve(?string $mode): string
    {
        if (\is_string($mode) && $mode !== '') {
            return $mode;
        }

        $enabledModes = $this->configProvider->getEnabledModes();
        if ($enabledModes !== []) {
            $first = $enabledModes[0]['name'] ?? null;
            if (\is_string($first) && $first !== '') {
                return $first;
            }
        }

        if ($this->logger !== null) {
            $this->logger->warning('trade_entry_mode_context.fallback', [
                'fallback' => $this->explicitDefault,
            ]);
        }

        return $this->explicitDefault;
    }

    /**
     * Expose la liste des modes activés (triés par priorité).
     *
     * @return array<int, array{name: string, enabled: bool, priority: int}>
     */
    public function getEnabledModes(): array
    {
        return $this->configProvider->getEnabledModes();
    }
}
