<?php

namespace App\MtfValidator\ConditionLoader\Cards\Rule;

use App\MtfValidator\ConditionLoader\Cards\AbstractCard;

class RuleElementCustomOp extends AbstractCard implements RuleElementInterface
{
    public string $customOp = '';
    public string|float|int|null $left = null;
    public string|float|int|null $right = null;
    public float|int|string|null $eps = null;
    public function fill(array|string $data): static
    {
        // $data = ['op' => '>', 'left' => 'macd_hist', 'right' => 0, 'eps' => '1.0E-6']
        $this->customOp = (string)($data['op']   ?? $data['customOp'] ?? '');
        $this->left     = $data['left'] ?? null;
        $this->right    = $data['right'] ?? null;

        $eps = $data['eps'] ?? null;
        if (is_string($eps) && is_numeric($eps)) {
            $eps = (float)$eps;
        }
        $this->eps = is_float($eps) || is_int($eps) ? (float)$eps : $eps;

        return $this;
    }
}
