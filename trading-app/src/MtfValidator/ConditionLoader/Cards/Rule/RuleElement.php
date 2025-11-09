<?php

namespace App\MtfValidator\ConditionLoader\Cards\Rule;

use App\MtfValidator\ConditionLoader\Cards\AbstractCard;

final class RuleElement extends AbstractCard implements RuleElementInterface
{
    private ?string $name = null;                // nom de règle OU null si valeur scalaire pure
    private float|int|string|bool|null $value = null; // valeur numérique/boolean/texte

    public function fill(array|string|int|float|bool $data): static
    {
        // 1) Cas "alias/nom de règle" (ex: 'ema_20_gt_50')
        if (is_string($data) && !is_numeric($data)) {
            $this->name = $data;
            $this->value     = null;
            return $this;
        }

        // 2) Cas "clé => valeur" (ex: ['ema_20_minus_ema_50_gt' => -0.0008])
        if (is_array($data)) {
            $this->name = (string) key($data);
            $raw             = current($data);
            $this->value     = $this->normalizeScalar($raw);
            return $this;
        }

        // 3) Cas scalaire pur (ex: 78, 70, true, -0.0008) → valeur, sans condition
        $this->name = null;
        $this->value     = $this->normalizeScalar($data);
        return $this;
    }

    private function normalizeScalar(mixed $v): float|int|string|bool|null
    {
        if (is_bool($v) || $v === null) return $v;

        // Nombres sous forme de string (y compris "1.0E-6")
        if (is_string($v) && is_numeric($v)) {
            return (float) $v; // garder la précision décimale
        }

        if (is_int($v) || is_float($v)) {
            return $v; // ne pas caster le float en int !
        }

        // tout le reste reste string (ex: champs)
        return is_string($v) ? $v : null;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getValue(): float|int|string|bool|null
    {
        return $this->value;
    }
}
