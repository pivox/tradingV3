<?php

namespace App\Indicator\Condition;

/**
 * Interface générique pour une condition d'indicateur logique.
 * Chaque implémentation reçoit un contexte d'évaluation (données de marché + indicateurs)
 * et retourne une structure normalisée conviviale pour le logging / audit / pipeline.
 */
interface ConditionInterface
{
    /** Nom (clé) utilisé dans la configuration YAML (ex: ema_20_gt_50). */
    public function getName(): string;

    /**
     * Évalue la condition.
     * @param array $context Contexte indicateurs + marché.
     * @return ConditionResult Résultat object (convertible en array via toArray()).
     */
    public function evaluate(array $context): ConditionResult;
}
