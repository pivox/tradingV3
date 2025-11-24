<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Rule;

final class TimeframeRuleEvaluator
{
    public function __construct(
        private readonly YamlRuleEngine $ruleEngine,
    ) {
    }

    /**
     * Évalue les règles d'un timeframe pour un side donné (long/short).
     *
     * @param array<string,mixed> $rulesConfig       mtf_validation.rules
     * @param array<string,mixed> $validationConfig  mtf_validation.validation
     * @param array<string,mixed> $indicators        indicateurs du TF courant
     */
    public function evaluateTimeframeSide(
        string $timeframe,
        string $side,                // 'long' ou 'short'
        array $rulesConfig,
        array $validationConfig,
        array $indicators
    ): bool {
        $tfConfig = $validationConfig['timeframe'][$timeframe][$side] ?? null;

        if ($tfConfig === null) {
            // Aucun scénario pour ce timeframe+side
            return false;
        }

        // Exemple YAML :
        // validation.timeframe.5m.long:
        //   - all_of: [...]
        //   - all_of: [...]
        $cases = $tfConfig;

        foreach ($cases as $caseBlock) {
            $ok = $this->ruleEngine->evaluate(
                $caseBlock,
                $rulesConfig,
                $indicators,
                $timeframe
            );

            // On considère le timeframe+side comme valide si AU MOINS
            // un scénario passe.
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
