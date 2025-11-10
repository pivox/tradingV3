#!/usr/bin/env php
<?php

/**
 * Script de validation structurelle des fichiers YAML scalper vs standard
 * Compare uniquement les structures (clés, hiérarchie) sans les valeurs
 */

if (php_sapi_name() !== 'cli') {
    die("Ce script doit être exécuté en ligne de commande\n");
}

// Charger Symfony YAML (depuis vendor)
$vendorPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorPath)) {
    die("Erreur: vendor/autoload.php non trouvé. Exécutez 'composer install'\n");
}
require_once $vendorPath;

use Symfony\Component\Yaml\Yaml;

class YamlStructureValidator
{
    private array $errors = [];
    private array $warnings = [];

    /**
     * Compare deux structures YAML et retourne les différences
     */
    public function compareStructures(array $standard, array $scalper, string $path = ''): void
    {
        // Vérifier les clés manquantes dans scalper
        foreach ($standard as $key => $value) {
            $currentPath = $path ? "$path.$key" : $key;
            
            if (!isset($scalper[$key])) {
                $this->warnings[] = "⚠️  Clé manquante dans scalper: $currentPath (présente dans standard)";
                continue;
            }

            // Comparer les types
            $standardType = $this->getType($value);
            $scalperType = $this->getType($scalper[$key]);

            if ($standardType !== $scalperType) {
                $this->errors[] = "❌ Type différent pour $currentPath: standard=$standardType, scalper=$scalperType";
                continue;
            }

            // Si ce sont des tableaux, comparer récursivement
            if (is_array($value) && is_array($scalper[$key])) {
                // Vérifier si c'est un tableau associatif ou indexé
                if ($this->isAssociative($value) && $this->isAssociative($scalper[$key])) {
                    $this->compareStructures($value, $scalper[$key], $currentPath);
                } elseif (!$this->isAssociative($value) && !$this->isAssociative($scalper[$key])) {
                    // Tableaux indexés: on vérifie juste la présence (pas l'ordre)
                    // Pas de comparaison approfondie pour les listes
                }
            }
        }

        // Vérifier les clés supplémentaires dans scalper (avertissements)
        foreach ($scalper as $key => $value) {
            $currentPath = $path ? "$path.$key" : $key;
            
            if (!isset($standard[$key])) {
                $this->warnings[] = "⚠️  Clé supplémentaire dans scalper: $currentPath (absente du standard)";
                continue;
            }
        }
    }

    /**
     * Obtient le type simplifié d'une valeur
     */
    private function getType($value): string
    {
        if (is_array($value)) {
            return $this->isAssociative($value) ? 'array_assoc' : 'array_list';
        }
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value)) {
            return 'int';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_null($value)) {
            return 'null';
        }
        return 'unknown';
    }

    /**
     * Vérifie si un tableau est associatif
     */
    private function isAssociative(array $array): bool
    {
        if (empty($array)) {
            return true; // Tableau vide considéré comme associatif par défaut
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Valide la structure complète d'un fichier de validations MTF
     */
    public function validateValidationsStructure(array $standard, array $scalper): void
    {
        // Vérifier la structure racine
        $this->compareStructures($standard, $scalper);

        // Vérifications spécifiques pour validations.yaml
        if (isset($standard['mtf_validation']) && isset($scalper['mtf_validation'])) {
            $stdMtf = $standard['mtf_validation'];
            $scalMtf = $scalper['mtf_validation'];

            // Vérifier les sections principales
            $requiredSections = ['mode', 'context_timeframes', 'execution_timeframe_default', 'allow_skip_lower_tf', 'defaults', 'rules', 'execution_selector', 'filters_mandatory', 'validation'];
            
            foreach ($requiredSections as $section) {
                if (isset($stdMtf[$section]) && !isset($scalMtf[$section])) {
                    $this->errors[] = "❌ Section manquante dans scalper: mtf_validation.$section";
                } elseif (!isset($stdMtf[$section]) && isset($scalMtf[$section])) {
                    $this->warnings[] = "⚠️  Section supplémentaire dans scalper: mtf_validation.$section";
                }
            }

            // Vérifier la structure des règles (clés des règles, pas les valeurs)
            if (isset($stdMtf['rules']) && isset($scalMtf['rules'])) {
                $stdRules = array_keys($stdMtf['rules']);
                $scalRules = array_keys($scalMtf['rules']);
                
                foreach ($stdRules as $ruleName) {
                    if (!in_array($ruleName, $scalRules)) {
                        $this->warnings[] = "⚠️  Règle manquante dans scalper: mtf_validation.rules.$ruleName";
                    } else {
                        // Comparer la structure de la règle (clés internes)
                        $this->compareStructures(
                            $stdMtf['rules'][$ruleName],
                            $scalMtf['rules'][$ruleName],
                            "mtf_validation.rules.$ruleName"
                        );
                    }
                }
                
                foreach ($scalRules as $ruleName) {
                    if (!in_array($ruleName, $stdRules)) {
                        $this->warnings[] = "⚠️  Règle supplémentaire dans scalper: mtf_validation.rules.$ruleName";
                    }
                }
            }

            // Vérifier la structure de execution_selector
            if (isset($stdMtf['execution_selector']) && isset($scalMtf['execution_selector'])) {
                $execSelectorSections = ['stay_on_15m_if', 'drop_to_5m_if_any', 'forbid_drop_to_5m_if_any', 'allow_1m_only_for'];
                foreach ($execSelectorSections as $subSection) {
                    if (isset($stdMtf['execution_selector'][$subSection]) && !isset($scalMtf['execution_selector'][$subSection])) {
                        $this->warnings[] = "⚠️  Sous-section manquante dans scalper: mtf_validation.execution_selector.$subSection";
                    } elseif (!isset($stdMtf['execution_selector'][$subSection]) && isset($scalMtf['execution_selector'][$subSection])) {
                        $this->warnings[] = "⚠️  Sous-section supplémentaire dans scalper: mtf_validation.execution_selector.$subSection";
                    }
                }
            }

            // Vérifier la structure de validation.timeframe
            if (isset($stdMtf['validation']['timeframe']) && isset($scalMtf['validation']['timeframe'])) {
                $stdTimeframes = array_keys($stdMtf['validation']['timeframe']);
                $scalTimeframes = array_keys($scalMtf['validation']['timeframe']);

                foreach ($stdTimeframes as $tf) {
                    if (!in_array($tf, $scalTimeframes)) {
                        $this->errors[] = "❌ Timeframe manquant dans scalper: validation.timeframe.$tf";
                    } else {
                        // Vérifier long/short
                        if (isset($stdMtf['validation']['timeframe'][$tf]['long']) && !isset($scalMtf['validation']['timeframe'][$tf]['long'])) {
                            $this->errors[] = "❌ Section manquante: validation.timeframe.$tf.long";
                        }
                        if (isset($stdMtf['validation']['timeframe'][$tf]['short']) && !isset($scalMtf['validation']['timeframe'][$tf]['short'])) {
                            $this->errors[] = "❌ Section manquante: validation.timeframe.$tf.short";
                        }
                    }
                }

                foreach ($scalTimeframes as $tf) {
                    if (!in_array($tf, $stdTimeframes)) {
                        $this->warnings[] = "⚠️  Timeframe supplémentaire dans scalper: validation.timeframe.$tf";
                    }
                }
            }
        }
    }

    /**
     * Valide la structure complète d'un fichier trade_entry
     */
    public function validateTradeEntryStructure(array $standard, array $scalper): void
    {
        // Vérifier la structure racine
        $this->compareStructures($standard, $scalper);

        // Vérifications spécifiques pour trade_entry.yaml
        if (isset($standard['trade_entry']) && isset($scalper['trade_entry'])) {
            $stdTe = $standard['trade_entry'];
            $scalTe = $scalper['trade_entry'];

            // Vérifier les sections principales
            $requiredSections = ['defaults', 'risk', 'leverage', 'decision', 'entry', 'post_validation'];
            
            foreach ($requiredSections as $section) {
                if (isset($stdTe[$section]) && !isset($scalTe[$section])) {
                    $this->errors[] = "❌ Section manquante dans scalper: trade_entry.$section";
                } elseif (!isset($stdTe[$section]) && isset($scalTe[$section])) {
                    $this->warnings[] = "⚠️  Section supplémentaire dans scalper: trade_entry.$section";
                }
            }

            // Vérifier la structure de defaults (comparaison récursive)
            if (isset($stdTe['defaults']) && isset($scalTe['defaults'])) {
                $this->compareStructures($stdTe['defaults'], $scalTe['defaults'], 'trade_entry.defaults');
            }

            // Vérifier la structure de risk
            if (isset($stdTe['risk']) && isset($scalTe['risk'])) {
                $riskSections = ['fixed_risk_pct', 'daily_max_loss_pct', 'daily_max_loss_usdt', 'daily_loss_count_unrealized', 'max_concurrent_positions'];
                foreach ($riskSections as $subSection) {
                    if (isset($stdTe['risk'][$subSection]) && !isset($scalTe['risk'][$subSection])) {
                        $this->warnings[] = "⚠️  Sous-section manquante dans scalper: trade_entry.risk.$subSection";
                    }
                }
            }

            // Vérifier la structure de decision
            if (isset($stdTe['decision']) && isset($scalTe['decision'])) {
                $decisionSections = ['allowed_execution_timeframes', 'require_price_or_atr'];
                foreach ($decisionSections as $subSection) {
                    if (isset($stdTe['decision'][$subSection]) && !isset($scalTe['decision'][$subSection])) {
                        $this->warnings[] = "⚠️  Sous-section manquante dans scalper: trade_entry.decision.$subSection";
                    }
                }
            }

            // Vérifier la structure de leverage
            if (isset($stdTe['leverage']) && isset($scalTe['leverage'])) {
                $leverageSections = ['mode', 'floor', 'exchange_cap', 'per_symbol_caps', 'timeframe_multipliers', 'confidence_multiplier', 'conviction', 'rounding'];
                foreach ($leverageSections as $subSection) {
                    if (isset($stdTe['leverage'][$subSection]) && !isset($scalTe['leverage'][$subSection])) {
                        $this->warnings[] = "⚠️  Sous-section manquante dans scalper: trade_entry.leverage.$subSection";
                    } elseif (isset($stdTe['leverage'][$subSection]) && isset($scalTe['leverage'][$subSection])) {
                        // Comparer récursivement les sous-sections complexes
                        if (is_array($stdTe['leverage'][$subSection]) && is_array($scalTe['leverage'][$subSection])) {
                            $this->compareStructures(
                                $stdTe['leverage'][$subSection],
                                $scalTe['leverage'][$subSection],
                                "trade_entry.leverage.$subSection"
                            );
                        }
                    }
                }
            }

            // Vérifier la structure de entry
            if (isset($stdTe['entry']) && isset($scalTe['entry'])) {
                $entrySections = ['prefer_maker', 'fallback_taker', 'budget', 'quantization', 'slippage_guard_bps', 'spread_guard_bps'];
                foreach ($entrySections as $subSection) {
                    if (isset($stdTe['entry'][$subSection]) && !isset($scalTe['entry'][$subSection])) {
                        $this->warnings[] = "⚠️  Sous-section manquante dans scalper: trade_entry.entry.$subSection";
                    } elseif (isset($stdTe['entry'][$subSection]) && isset($scalTe['entry'][$subSection])) {
                        // Comparer récursivement les sous-sections complexes
                        if (is_array($stdTe['entry'][$subSection]) && is_array($scalTe['entry'][$subSection])) {
                            $this->compareStructures(
                                $stdTe['entry'][$subSection],
                                $scalTe['entry'][$subSection],
                                "trade_entry.entry.$subSection"
                            );
                        }
                    }
                }
            }

            // Vérifier la structure de post_validation
            if (isset($stdTe['post_validation']) && isset($scalTe['post_validation'])) {
                $postValSections = ['entry_zone', 'idempotency'];
                foreach ($postValSections as $subSection) {
                    if (isset($stdTe['post_validation'][$subSection]) && !isset($scalTe['post_validation'][$subSection])) {
                        $this->warnings[] = "⚠️  Sous-section manquante dans scalper: trade_entry.post_validation.$subSection";
                    } elseif (isset($stdTe['post_validation'][$subSection]) && isset($scalTe['post_validation'][$subSection])) {
                        // Comparer récursivement les sous-sections complexes
                        if (is_array($stdTe['post_validation'][$subSection]) && is_array($scalTe['post_validation'][$subSection])) {
                            $this->compareStructures(
                                $stdTe['post_validation'][$subSection],
                                $scalTe['post_validation'][$subSection],
                                "trade_entry.post_validation.$subSection"
                            );
                        }
                    }
                }
            }
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}

// ============================================================================
// EXECUTION
// ============================================================================

$baseDir = __DIR__ . '/..';

echo "═══════════════════════════════════════════════════════════════\n";
echo "  VALIDATION STRUCTURELLE DES FICHIERS YAML SCALPER\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ============================================================================
// 1. VALIDATION: validations.yaml vs validations.scalper.yaml
// ============================================================================

echo "📋 Validation 1/2: validations.yaml\n";
echo "───────────────────────────────────────────────────────────────\n";

$stdValidationsPath = "$baseDir/src/MtfValidator/config/validations.yaml";
$scalValidationsPath = "$baseDir/src/MtfValidator/config/validations.scalper.yaml";

if (!file_exists($stdValidationsPath)) {
    die("❌ Fichier standard non trouvé: $stdValidationsPath\n");
}
if (!file_exists($scalValidationsPath)) {
    die("❌ Fichier scalper non trouvé: $scalValidationsPath\n");
}

try {
    $stdValidations = Yaml::parseFile($stdValidationsPath);
    $scalValidations = Yaml::parseFile($scalValidationsPath);
} catch (\Exception $e) {
    die("❌ Erreur de parsing YAML: " . $e->getMessage() . "\n");
}

$validator1 = new YamlStructureValidator();
$validator1->validateValidationsStructure($stdValidations, $scalValidations);

$errors1 = $validator1->getErrors();
$warnings1 = $validator1->getWarnings();

if (empty($errors1) && empty($warnings1)) {
    echo "✅ Structure identique - Aucune différence détectée\n\n";
} else {
    if (!empty($errors1)) {
        echo "\n❌ ERREURS STRUCTURELLES (" . count($errors1) . "):\n";
        foreach ($errors1 as $error) {
            echo "   $error\n";
        }
    }
    if (!empty($warnings1)) {
        echo "\n⚠️  AVERTISSEMENTS (" . count($warnings1) . "):\n";
        foreach ($warnings1 as $warning) {
            echo "   $warning\n";
        }
    }
    echo "\n";
}

// ============================================================================
// 2. VALIDATION: trade_entry.yaml vs trade_entry.scalper.yaml
// ============================================================================

echo "📋 Validation 2/2: trade_entry.yaml\n";
echo "───────────────────────────────────────────────────────────────\n";

$stdTradeEntryPath = "$baseDir/config/app/trade_entry.yaml";
$scalTradeEntryPath = "$baseDir/config/app/trade_entry.scalper.yaml";

if (!file_exists($stdTradeEntryPath)) {
    die("❌ Fichier standard non trouvé: $stdTradeEntryPath\n");
}
if (!file_exists($scalTradeEntryPath)) {
    die("❌ Fichier scalper non trouvé: $scalTradeEntryPath\n");
}

try {
    $stdTradeEntry = Yaml::parseFile($stdTradeEntryPath);
    $scalTradeEntry = Yaml::parseFile($scalTradeEntryPath);
} catch (\Exception $e) {
    die("❌ Erreur de parsing YAML: " . $e->getMessage() . "\n");
}

$validator2 = new YamlStructureValidator();
$validator2->validateTradeEntryStructure($stdTradeEntry, $scalTradeEntry);

$errors2 = $validator2->getErrors();
$warnings2 = $validator2->getWarnings();

if (empty($errors2) && empty($warnings2)) {
    echo "✅ Structure identique - Aucune différence détectée\n\n";
} else {
    if (!empty($errors2)) {
        echo "\n❌ ERREURS STRUCTURELLES (" . count($errors2) . "):\n";
        foreach ($errors2 as $error) {
            echo "   $error\n";
        }
    }
    if (!empty($warnings2)) {
        echo "\n⚠️  AVERTISSEMENTS (" . count($warnings2) . "):\n";
        foreach ($warnings2 as $warning) {
            echo "   $warning\n";
        }
    }
    echo "\n";
}

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================

echo "═══════════════════════════════════════════════════════════════\n";
echo "  RÉSUMÉ\n";
echo "═══════════════════════════════════════════════════════════════\n";

$totalErrors = count($errors1) + count($errors2);
$totalWarnings = count($warnings1) + count($warnings2);

if ($totalErrors === 0 && $totalWarnings === 0) {
    echo "✅ VALIDATION RÉUSSIE\n";
    echo "   Les structures des fichiers scalper correspondent aux fichiers standard.\n";
    exit(0);
} else {
    echo "📊 Statistiques:\n";
    echo "   • Erreurs structurelles: $totalErrors\n";
    echo "   • Avertissements: $totalWarnings\n";
    echo "\n";
    
    if ($totalErrors > 0) {
        echo "❌ VALIDATION ÉCHOUÉE\n";
        echo "   Des différences structurelles critiques ont été détectées.\n";
        exit(1);
    } else {
        echo "⚠️  VALIDATION AVEC AVERTISSEMENTS\n";
        echo "   La structure est compatible mais des différences mineures existent.\n";
        exit(0);
    }
}

