<?php

namespace App\Provider\Bitmart;

use App\Contract\Provider\SystemProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsAlias(id: SystemProviderInterface::class)]
readonly class SystemProvider implements SystemProviderInterface
{
    public function __construct(
        private BitmartHttpClientPublic $bitmartClient
    ) {}

    /**
     * @throws TimeoutExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function getSystemTimeMs(): int
    {
        return $this->bitmartClient->getSystemTimeMs();
    }
}
