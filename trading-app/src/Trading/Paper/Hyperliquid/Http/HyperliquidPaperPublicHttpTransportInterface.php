<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

interface HyperliquidPaperPublicHttpTransportInterface
{
    /**
     * @param array{type: 'candleSnapshot', req: array{coin: string, interval: string, startTime: int, endTime: int}} $payload
     */
    public function postCandleSnapshot(string $uri, array $payload): ResponseInterface;

    /** @param array{type: 'meta'} $payload */
    public function postMetadata(string $uri, array $payload): ResponseInterface;

    /** @param array{type: 'metaAndAssetCtxs'} $payload */
    public function postFundingContext(string $uri, array $payload): ResponseInterface;

    public function stream(ResponseInterface $response): ResponseStreamInterface;
}
