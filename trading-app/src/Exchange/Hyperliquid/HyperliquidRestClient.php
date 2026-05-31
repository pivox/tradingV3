<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(id: HyperliquidRestClientInterface::class)]
final readonly class HyperliquidRestClient implements HyperliquidRestClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private HyperliquidConfig $config,
    ) {
    }

    public function info(array $request): array
    {
        /** @var array<string,mixed> $data */
        $data = $this->httpClient
            ->request('POST', $this->config->apiBaseUri() . '/info', ['json' => $request])
            ->toArray(false);

        return $data;
    }

    public function exchange(array $action): array
    {
        $this->config->assertTradingConfigured();

        throw new \RuntimeException('Hyperliquid exchange signing is not enabled in this adapter yet; inject a signed client before live trading.');
    }
}
