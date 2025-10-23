<?php

namespace App\Provider\Bitmart\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;


class BitmartConfig
{
    public function __construct(
        #[Autowire(env: 'BITMART_API_KEY')] private string    $apiKey,
        #[Autowire(env: 'BITMART_SECRET_KEY')] private string $apiSecret,
        #[Autowire(env: 'BITMART_API_MEMO')] private string   $apiMemo,
    )
    {
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    public function getApiMemo(): string
    {
        return $this->apiMemo;
    }
}
