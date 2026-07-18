<?php

declare(strict_types=1);

namespace App\Runtime\Safety;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ExchangeCallGuardHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $client,
        private readonly FakeOnlyExchangeCallAudit $audit,
        private readonly string $exchange,
    ) {
        $this->client = $client;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->audit->isActive()) {
            $this->audit->recordAttempt($this->exchange);

            throw new FakeOnlyExchangeCallBlockedException(sprintf('fake_only_exchange_call_blocked:%s', $this->exchange));
        }

        return $this->client->request($method, $url, $options);
    }
}
