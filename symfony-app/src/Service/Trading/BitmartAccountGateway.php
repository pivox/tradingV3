<?php
declare(strict_types=1);


namespace App\Service\Trading;

interface BitmartAccountGateway
{
    public function getEquity(): float;
    public function getAvailableUSDT(): float;
}
