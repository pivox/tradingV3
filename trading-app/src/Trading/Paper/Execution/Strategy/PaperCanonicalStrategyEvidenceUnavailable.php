<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

final class PaperCanonicalStrategyEvidenceUnavailable extends \RuntimeException
{
    private function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }

    public static function indicatorProjection(): self
    {
        return new self('paper_indicator_projection_unavailable');
    }

    public static function orderBook(): self
    {
        return new self('paper_order_book_unavailable');
    }

    public static function instrument(): self
    {
        return new self('paper_instrument_unavailable');
    }

    public static function executionCosts(): self
    {
        return new self('paper_execution_cost_unavailable');
    }

    public static function orderPlan(): self
    {
        return new self('paper_order_plan_unavailable');
    }
}
