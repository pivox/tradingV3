<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AsAlias(id: HyperliquidRestClientInterface::class)]
final readonly class HyperliquidRestClient implements HyperliquidRestClientInterface, HyperliquidReadinessInfoClientInterface
{
    private const TESTNET_ENDPOINT = 'https://api.hyperliquid-testnet.xyz';
    private const MAINNET_ENDPOINT = 'https://api.hyperliquid.xyz';
    private const MAX_RESPONSE_BYTES = 65_536;

    public function __construct(
        private HttpClientInterface $httpClient,
        private HyperliquidConfig $config,
    ) {
    }

    public function info(array $request): array
    {
        $this->assertOfficialEndpoint();

        return $this->requestInfo($request);
    }

    public function readinessInfo(array $request): array
    {
        if ($this->config->apiBaseUri() !== self::TESTNET_ENDPOINT) {
            throw new \RuntimeException('hyperliquid_readiness_testnet_endpoint_required');
        }
        $this->assertOfficialEndpoint();

        return $this->requestInfo($request);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<mixed>
     */
    private function requestInfo(array $request): array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->config->apiBaseUri() . '/info',
                [
                    'json' => $request,
                    'timeout' => 5.0,
                    'max_duration' => 5.0,
                    'max_redirects' => 0,
                    'proxy' => null,
                    'no_proxy' => '*',
                ],
            );
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf('hyperliquid_info_http_status_%d', $statusCode));
            }

            $body = $this->boundedBody($response);
        } catch (TransportExceptionInterface) {
            throw new \RuntimeException('hyperliquid_info_transport_failed');
        }

        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('hyperliquid_info_response_malformed', previous: $exception);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('hyperliquid_info_response_malformed');
        }

        return $data;
    }

    private function boundedBody(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                throw new \RuntimeException('hyperliquid_info_transport_failed');
            }
            $content = $chunk->getContent();
            if (strlen($body) + strlen($content) > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('hyperliquid_info_response_too_large');
            }
            $body .= $content;
        }

        return $body;
    }

    private function assertOfficialEndpoint(): void
    {
        $environment = $this->config->configuredEnvironment();
        $network = $this->config->normalizedNetwork();
        $endpoint = $this->config->apiBaseUri();
        $testnet = $environment === 'testnet'
            && $network === 'testnet'
            && $endpoint === self::TESTNET_ENDPOINT
            && !$this->config->mainnetEnabled;
        $mainnet = $environment === 'mainnet'
            && $network === 'mainnet'
            && $endpoint === self::MAINNET_ENDPOINT
            && $this->config->mainnetEnabled;
        if (!$testnet && !$mainnet) {
            throw new \RuntimeException('hyperliquid_info_endpoint_not_allowed');
        }
    }

    public function exchange(array $action): array
    {
        $this->config->assertTradingConfigured();

        throw new \RuntimeException('Hyperliquid exchange signing is not enabled in this adapter yet; inject a signed client before live trading.');
    }
}
