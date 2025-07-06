<?php

namespace App\Service\Exchange;

interface ExchangeFetcherInterface {
    public function fetchContracts(): array;
    public function fetchKlines(string $symbol, \DateTimeInterface $start, \DateTimeInterface $end, int $step): array;
}
