<?php

namespace App\Indicator\ConditionLoader;

use App\Config\MtfValidationConfig;
use App\Indicator\ConditionLoader\Cards\Validation\Side;
use App\Indicator\ConditionLoader\Cards\Validation\TimeframeValidation;

/**
 * Normalise l'évaluation d'un timeframe (long/short) à partir d'un contexte indicateur.
 * Retourne la même structure pour les services de signaux, le contrôleur web, etc.
 */
class TimeframeEvaluator
{
    public function __construct(
        private readonly ConditionRegistry $registry
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array{
     *     passed: array{long:bool,short:bool},
     *     long: array{conditions:array<string,array>,failed:string[],requirements:array},
     *     short: array{conditions:array<string,array>,failed:string[],requirements:array}
     * }
     */
    public function evaluate(string $timeframe, array $context): array
    {
        $card = $this->getTimeframeCard($timeframe);
        if (!$card) {
            return $this->emptyEvaluation();
        }

        $result = $card->evaluate($context);

        $longSide = $result[Side::LONG] ?? ['passed' => false, 'conditions' => []];
        $shortSide = $result[Side::SHORT] ?? ['passed' => false, 'conditions' => []];

        $longConditions = $this->collectConditions($longSide['conditions'] ?? []);
        $shortConditions = $this->collectConditions($shortSide['conditions'] ?? []);

        return [
            'passed' => [
                'long' => $result['passed'][Side::LONG] ?? false,
                'short' => $result['passed'][Side::SHORT] ?? false,
            ],
            'long' => [
                'conditions' => $longConditions,
                'failed' => $this->extractFailed($longConditions),
                'requirements' => $longSide['conditions'] ?? [],
            ],
            'short' => [
                'conditions' => $shortConditions,
                'failed' => $this->extractFailed($shortConditions),
                'requirements' => $shortSide['conditions'] ?? [],
            ],
        ];
    }

    private function extractFailed(array $conditions): array
    {
        $failed = [];
        foreach ($conditions as $name => $result) {
            if (($result['passed'] ?? false) !== true) {
                $failed[] = $name;
            }
        }
        return $failed;
    }

    private function collectConditions(array $nodes): array
    {
        $collected = [];
        $this->traverseConditionNodes($nodes, $collected);
        return $collected;
    }

    private function traverseConditionNodes(array $nodes, array &$collected): void
    {
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if (isset($node['name']) && \is_string($node['name'])) {
                $collected[$node['name']] = $node;
            }
            if (isset($node['items']) && \is_array($node['items'])) {
                $this->traverseConditionNodes($node['items'], $collected);
            }
        }
    }

    private function getTimeframeCard(string $tf): ?TimeframeValidation
    {
        $validation = $this->registry->getValidation();
        if (!$validation) {
            $this->registry->load(new MtfValidationConfig());
            $validation = $this->registry->getValidation();
        }
        if (!$validation) {
            throw new \RuntimeException('MTF validation configuration not loaded');
        }

        $timeframes = $validation->getTimeframes();
        return $timeframes[$tf] ?? null;
    }

    private function emptyEvaluation(): array
    {
        return [
            'passed' => ['long' => false, 'short' => false],
            'long' => [
                'conditions' => [],
                'failed' => [],
                'requirements' => [],
            ],
            'short' => [
                'conditions' => [],
                'failed' => [],
                'requirements' => [],
            ],
        ];
    }
}
