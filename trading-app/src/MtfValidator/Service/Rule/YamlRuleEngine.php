<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Rule;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

#[Lazy]
#[AsAlias]
class YamlRuleEngine implements RuleEngineInterface
{
    /**
     * Évalue un bloc de règle YAML (string, all_of, any_of, primitif ou alias).
     *
     * @param array<string,mixed>|string $block        Bloc issu du YAML (règle, all_of, any_of, etc.)
     * @param array<string,mixed>        $rulesConfig  Section "rules" complète du YAML
     * @param array<string,mixed>        $indicators   Indicateurs pour le timeframe courant
     */
    public function evaluate(
        array|string $block,
        array $rulesConfig,
        array $indicators,
        string $timeframe
    ): bool {
        // Cas simple : une chaîne → nom de règle
        if (\is_string($block)) {
            return $this->evaluateNamedRule(
                ruleName: $block,
                rulesConfig: $rulesConfig,
                overrides: [],
                indicators: $indicators,
                timeframe: $timeframe
            );
        }

        // Cas array : on regarde les clés "structurantes"
        if (isset($block['all_of'])) {
            return $this->evaluateAllOf(
                $block['all_of'],
                $rulesConfig,
                $indicators,
                $timeframe
            );
        }

        if (isset($block['any_of'])) {
            return $this->evaluateAnyOf(
                $block['any_of'],
                $rulesConfig,
                $indicators,
                $timeframe
            );
        }

        // Sinon : bloc primitif OU alias avec override
        return $this->evaluatePrimitiveBlock(
            $block,
            $rulesConfig,
            $indicators,
            $timeframe
        );
    }

    /**
     * Évalue une règle nommée depuis la section "rules" du YAML.
     *
     * @param array<string,mixed> $rulesConfig
     * @param array<string,mixed> $overrides
     * @param array<string,mixed> $indicators
     */
    public function evaluateNamedRule(
        string $ruleName,
        array $rulesConfig,
        array $overrides,
        array $indicators,
        string $timeframe
    ): bool {
        if (!isset($rulesConfig[$ruleName])) {
            throw new \RuntimeException(\sprintf(
                'Unknown rule name "%s". Available rules: %s',
                $ruleName,
                \implode(', ', \array_keys($rulesConfig))
            ));
        }

        $ruleDef = $rulesConfig[$ruleName];

        if (!empty($overrides)) {
            $ruleDef = \array_merge($ruleDef, $overrides);
        }

        // La règle nommée peut elle-même contenir all_of / any_of / primitives
        $result = $this->evaluate($ruleDef, $rulesConfig, $indicators, $timeframe);

        // Logging léger pour debug des règles clés (ema_50_gt_200, ema_above_200_with_tolerance_moderate, etc.)
        if (\in_array($ruleName, ['ema_50_gt_200', 'ema_above_200_with_tolerance', 'ema_above_200_with_tolerance_moderate'], true)) {
            $this->debugRule($ruleName, $result, $indicators, $timeframe, $ruleDef);
        }

        return $result;
    }

    /**
     * @param array<int,mixed>  $blocks
     * @param array<string,mixed> $rulesConfig
     * @param array<string,mixed> $indicators
     */
    private function evaluateAllOf(
        array $blocks,
        array $rulesConfig,
        array $indicators,
        string $timeframe
    ): bool {
        foreach ($blocks as $sub) {
            if (!$this->evaluate($sub, $rulesConfig, $indicators, $timeframe)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,mixed>    $blocks
     * @param array<string,mixed> $rulesConfig
     * @param array<string,mixed> $indicators
     */
    private function evaluateAnyOf(
        array $blocks,
        array $rulesConfig,
        array $indicators,
        string $timeframe
    ): bool {
        foreach ($blocks as $sub) {
            if ($this->evaluate($sub, $rulesConfig, $indicators, $timeframe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Évalue un "bloc primitif" ou un alias avec override.
     *
     * Exemples de blocs possibles :
     *  - { lt_fields: ['close','ema_200'] }
     *  - { gt_fields: ['ema_50','ema_200'] }
     *  - { op: '>', left: 'macd_hist', right: 0.0, eps: 1e-6 }
     *  - { lt: 72 }  (scalar comparison avec field implicite, ex: rsi)
     *  - { rsi_lt_70: { lt: 72 } }  (alias vers une règle nommée avec override)
     *
     * @param array<string,mixed> $block
     * @param array<string,mixed> $rulesConfig
     * @param array<string,mixed> $indicators
     */
    private function evaluatePrimitiveBlock(
        array $block,
        array $rulesConfig,
        array $indicators,
        string $timeframe
    ): bool {
        // 1) Cas alias avec override :
        //    ex: [ 'rsi_lt_70' => ['lt' => 72] ]
        if (\count($block) === 1) {
            $ruleName = \array_key_first($block);
            $overrides = $block[$ruleName];

            // Clés réservées pour les primitives inline
            $primitiveKeys = [
                'lt_fields', 'gt_fields',
                'op', 'left', 'right', 'eps',
                'lt', 'gt', 'field',
            ];

            // Si la clé n'est pas une primitive inline et existe dans rulesConfig → alias
            if (!\in_array($ruleName, $primitiveKeys, true)
                && \array_key_exists($ruleName, $rulesConfig)
            ) {
                if (!\is_array($overrides)) {
                    // Au besoin, tu peux adapter cette convention
                    $overrides = ['value' => $overrides];
                }

                return $this->evaluateNamedRule(
                    ruleName: $ruleName,
                    rulesConfig: $rulesConfig,
                    overrides: $overrides,
                    indicators: $indicators,
                    timeframe: $timeframe
                );
            }
        }

        // 2) Primitifs connus

        // lt_fields / gt_fields
        if (isset($block['lt_fields'])) {
            return $this->evaluateLtFields($block['lt_fields'], $indicators);
        }

        if (isset($block['gt_fields'])) {
            return $this->evaluateGtFields($block['gt_fields'], $indicators);
        }

        // op + left + right (+ eps)
        if (isset($block['op'], $block['left'])) {
            return $this->evaluateOpLeftRight($block, $indicators);
        }

        // Cas scalaires : { lt: 72 } ou { gt: 30 }, avec field optionnel
        if (isset($block['lt']) || isset($block['gt'])) {
            return $this->evaluateScalarComparison($block, $indicators);
        }

        // TODO: ajouter derivative_gt/lt, slope_left, increasing, decreasing, near_vwap, etc.
        // ⚠️ Pour l'instant, si on tombe ici avec un bloc inconnu, on retourne true
        // pour ne pas bloquer toute la validation. Tu pourras durcir plus tard.
        return true;
    }

    /**
     * @param array<int,string>   $fields
     * @param array<string,mixed> $indicators
     */
    private function evaluateLtFields(array $fields, array $indicators): bool
    {
        if (\count($fields) !== 2) {
            return true;
        }

        [$leftKey, $rightKey] = $fields;

        if (!\array_key_exists($leftKey, $indicators) || !\array_key_exists($rightKey, $indicators)) {
            return false;
        }

        return (float) $indicators[$leftKey] < (float) $indicators[$rightKey];
    }

    /**
     * @param array<int,string>   $fields
     * @param array<string,mixed> $indicators
     */
    private function evaluateGtFields(array $fields, array $indicators): bool
    {
        if (\count($fields) !== 2) {
            return true;
        }

        [$leftKey, $rightKey] = $fields;

        if (!\array_key_exists($leftKey, $indicators) || !\array_key_exists($rightKey, $indicators)) {
            return false;
        }

        return (float) $indicators[$leftKey] > (float) $indicators[$rightKey];
    }

    /**
     * Bloc du type:
     *   op: '>'
     *   left: 'macd_hist'
     *   right: 0.0
     *   eps: 1e-6
     *
     * @param array<string,mixed> $block
     * @param array<string,mixed> $indicators
     */
    private function evaluateOpLeftRight(array $block, array $indicators): bool
    {
        $op = $block['op'];
        $leftKey = $block['left'];
        $right = $block['right'] ?? 0.0;
        $eps = isset($block['eps']) ? (float) $block['eps'] : 0.0;

        if (!\array_key_exists($leftKey, $indicators)) {
            return false;
        }

        $leftValue = (float) $indicators[$leftKey];
        $rightValue = (float) $right;

        if ($eps > 0.0 && \abs($leftValue - $rightValue) < $eps) {
            $leftValue = $rightValue;
        }

        return match ($op) {
            '>'  => $leftValue > $rightValue,
            '>=' => $leftValue >= $rightValue,
            '<'  => $leftValue < $rightValue,
            '<=' => $leftValue <= $rightValue,
            '==' => $leftValue === $rightValue,
            '!=' => $leftValue !== $rightValue,
            default => true, // opérateur inconnu → on ne bloque pas
        };
    }

    /**
     * Cas type: { lt: 72 } ou { gt: 30 }.
     * On suppose que l'indicateur principal est "rsi" sauf si "field" est précisé.
     *
     * @param array<string,mixed> $block
     * @param array<string,mixed> $indicators
     */
    private function evaluateScalarComparison(array $block, array $indicators): bool
    {
        $field = $block['field'] ?? 'rsi';

        if (!\array_key_exists($field, $indicators)) {
            return false;
        }

        $value = (float) $indicators[$field];

        if (isset($block['lt'])) {
            return $value < (float) $block['lt'];
        }

        if (isset($block['gt'])) {
            return $value > (float) $block['gt'];
        }

        return true;
    }

    /**
     * Logging de debug pour quelques règles clés.
     *
     * @param array<string,mixed> $ruleDef
     * @param array<string,mixed> $indicators
     */
    private function debugRule(
        string $ruleName,
        bool $result,
        array $indicators,
        string $timeframe,
        array $ruleDef
    ): void {
        // Logging via error_log pour ne pas dépendre du container/logger
        $payload = [
            'rule'       => $ruleName,
            'timeframe'  => $timeframe,
            'result'     => $result ? 'PASS' : 'FAIL',
            'inputs'     => [
                'ema_50'  => $indicators['ema_50'] ?? null,
                'ema_200' => $indicators['ema_200'] ?? null,
                'close'   => $indicators['close'] ?? null,
            ],
            'definition' => $ruleDef,
        ];

        // Encodage compact pour les logs
        @error_log('[MTF_RULE_DEBUG] ' . \json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
