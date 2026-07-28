<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Http;

interface OkxPaperPublicRestClientInterface
{
    /** @return list<array<array-key, mixed>> */
    public function historyCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        int $limit = 300,
    ): array;

    /** @return list<array<array-key, mixed>> */
    public function currentCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        ?string $before = null,
        int $limit = 300,
    ): array;

    /** @return list<array<array-key, mixed>> */
    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array;

    /** @return list<array<array-key, mixed>> */
    public function recentTrades(string $instrumentId, int $limit = 500): array;

    /** @return list<array<array-key, mixed>> */
    public function orderBook(string $instrumentId, int $depth = 400): array;
}
