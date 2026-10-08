<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\Demo;

use App\Exchange\Okx\OkxRestClientInterface;

final class RecordingOkxClient implements OkxRestClientInterface
{
    /** @var list<array{0: string, 1: array<mixed>}> */
    public array $posts = [];

    public bool $throwOnPost = false;

    public function publicGet(string $path, array $query = []): array
    {
        return ['code' => '0', 'data' => [[
            'bids' => [['24999.5', '1']],
            'asks' => [['25000.5', '1']],
        ]]];
    }

    public function privateGet(string $path, array $query = []): array
    {
        return ['code' => '0', 'data' => []];
    }

    public function privatePost(string $path, array $body = []): array
    {
        if ($this->throwOnPost) {
            throw new \RuntimeException('boom');
        }
        $this->posts[] = [$path, $body];

        return match ($path) {
            '/api/v5/trade/order' => ['code' => '0', 'data' => [['ordId' => '12345', 'sCode' => '0']]],
            '/api/v5/trade/order-algo' => ['code' => '0', 'data' => [['algoId' => '90001', 'sCode' => '0']]],
            default => ['code' => '0', 'data' => [['sCode' => '0']]],
        };
    }
}
