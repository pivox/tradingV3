<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

interface ExchangeRuntimeCheckInterface
{
    public function check(ExchangeReadinessInput $input): ExchangeReadinessReport;
}
