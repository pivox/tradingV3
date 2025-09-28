<?php

namespace App\Service\Trading;

use App\Service\Exchange\Bitmart\BitmartFetcher;

class PositionFetcher
{
    public function __construct(
        private readonly BitmartFetcher $fetcher,
    ) {}

    public function fetchPosition(string $symbol): ?object
    {
        $data = $this->fetcher->fetchPosition($symbol);

        if (!$data) {
            return null;
        }

        return (object)[
            'side'       => $data['side'],
            'quantity'   => (float)$data['size'],
            'entryPrice' => (float)$data['entryPrice'],
            'markPrice'  => (float)$data['markPrice'],
        ];
    }
}
