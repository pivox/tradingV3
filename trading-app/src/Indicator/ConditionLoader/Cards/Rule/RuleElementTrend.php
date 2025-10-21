<?php

namespace App\Indicator\ConditionLoader\Cards\Rule;

use App\Indicator\ConditionLoader\Cards\AbstractCard;
use App\Indicator\ConditionLoader\Cards\Rule\RuleElementInterface;

class RuleElementTrend extends AbstractCard implements RuleElementInterface
{
    public const INCREASING = 'increasing';
    public const DECREASING = 'decreasing';

    private string $trend; // 'increasing' | 'decreasing'
    private RuleElement $field;
    private int $n = 1;
    private bool $strict = true;
    private float $eps = 1.0e-8;

    public function fill(array|string $data): static
    {
        $this->trend = (string) key($data);
        $attrs       = (array) current($data); // ['field' => 'macd_hist', 'n' => 2]
        $field = $attrs['field'] ?? null;
        if ($field === null) {
            throw new \InvalidArgumentException('Trend rule requires a "field" attribute');
        }

        $this->n     = max(1, (int) ($attrs['n'] ?? 1));
        $this->field = (new RuleElement())->fill($field);
        if (isset($attrs['strict'])) {
            $this->strict = (bool) $attrs['strict'];
        }
        if (isset($attrs['eps']) && is_numeric($attrs['eps'])) {
            $this->eps = (float) $attrs['eps'];
        }
        return $this;
    }

    public function getTrend(): string
    {
        return $this->trend;
    }

    public function getField(): RuleElement
    {
        return $this->field;
    }

    public function getN(): int
    {
        return $this->n;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    public function getEps(): float
    {
        return $this->eps;
    }
}
