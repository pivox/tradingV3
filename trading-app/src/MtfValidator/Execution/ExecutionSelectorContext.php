<?php

declare(strict_types=1);

namespace App\MtfValidator\Execution;

/**
 * DTO pour passer le contexte à ExecutionSelector
 */
final class ExecutionSelectorContext
{
    /**
     * @param string $symbol
     * @param string $side LONG ou SHORT (issu du contexte global)
     * @param string[] $executionTimeframes Liste des TF d'exécution à considérer
     * @param array<string,array<string,mixed>|null> $tfResults Résultats MTF pour tous les TF (4h → 1m)
     * @param array<string,mixed> $mtfConfig Section mtf_validation du YAML actif
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $side,
        private readonly array $executionTimeframes,
        public readonly array $tfResults,
        public readonly array $mtfConfig
    ) {}

    /**
     * Récupère les timeframes d'exécution depuis la config
     * @return array<string>
     */
    public function getExecutionTimeframes(): array
    {
        if ($this->executionTimeframes !== []) {
            return array_map('strtolower', $this->executionTimeframes);
        }

        $execTfs = $this->mtfConfig['execution_timeframes'] ?? [];
        if (empty($execTfs)) {
            // Fallback vers execution_timeframe_default si présent
            $defaultTf = $this->mtfConfig['execution_timeframe_default'] ?? '5m';
            return [$defaultTf, '1m'];
        }
        return array_map('strtolower', (array)$execTfs);
    }

    /**
     * Récupère la configuration execution_selector
     * @return array<string,mixed>
     */
    public function getExecutionSelectorConfig(): array
    {
        return (array)($this->mtfConfig['execution_selector'] ?? []);
    }

    /**
     * Récupère le résultat d'un timeframe spécifique
     * @return array<string,mixed>|null
     */
    public function getTimeframeResult(string $timeframe): ?array
    {
        $normalizedTf = strtolower($timeframe);
        return $this->tfResults[$normalizedTf] ?? null;
    }

    /**
     * Vérifie si un timeframe est VALID, aligné avec le side global et exécutable.
     */
    public function isTimeframeValid(string $timeframe): bool
    {
        $result = $this->getTimeframeResult($timeframe);
        if ($result === null) {
            return false;
        }
        $status = strtoupper((string)($result['status'] ?? ''));
        if ($status !== 'VALID') {
            return false;
        }

        $tfSide = strtoupper((string)($result['side'] ?? ($result['signal_side'] ?? '')));
        if ($tfSide === '') {
            return false;
        }

        return $tfSide === strtoupper((string)$this->side);
    }
}
