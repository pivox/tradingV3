<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

final class MtfTimeframeResolver
{
    /**
     * @param array<string, mixed> $mtfConfig
     * @return list<string>
     */
    public function resolveContext(array $mtfConfig): array
    {
        $context = $this->normalize($mtfConfig['context_timeframes'] ?? null);
        if ($context !== []) {
            return $context;
        }

        $validationTimeframes = $this->validationTimeframes($mtfConfig);

        return $validationTimeframes !== [] ? $validationTimeframes : ['4h', '1h'];
    }

    /**
     * @param array<string, mixed> $mtfConfig
     * @param list<string>|null    $contextTimeframes
     * @return list<string>
     */
    public function resolveExecution(array $mtfConfig, ?array $contextTimeframes = null): array
    {
        $execution = $this->normalize($mtfConfig['execution_timeframes'] ?? null);
        if ($execution !== []) {
            return $execution;
        }

        $execution = array_values(array_diff(
            $this->validationTimeframes($mtfConfig),
            $contextTimeframes ?? $this->resolveContext($mtfConfig),
        ));

        return $execution !== [] ? $execution : ['15m', '5m', '1m'];
    }

    /**
     * @param array<string, mixed> $mtfConfig
     * @return list<string>
     */
    public function resolveAll(array $mtfConfig): array
    {
        $context = $this->resolveContext($mtfConfig);

        return array_values(array_unique(array_merge(
            $context,
            $this->resolveExecution($mtfConfig, $context),
        )));
    }

    /**
     * @param array<string, mixed> $mtfConfig
     * @return list<string>
     */
    private function validationTimeframes(array $mtfConfig): array
    {
        return $this->normalize(array_keys($mtfConfig['validation']['timeframe'] ?? []));
    }

    /**
     * @return list<string>
     */
    private function normalize(mixed $timeframes): array
    {
        if (is_string($timeframes)) {
            $timeframes = [$timeframes];
        }

        if (!is_array($timeframes)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $timeframes,
            static fn (mixed $timeframe): bool => is_string($timeframe) && $timeframe !== '',
        )));
    }
}
