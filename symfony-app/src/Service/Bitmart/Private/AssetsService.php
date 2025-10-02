<?php

declare(strict_types=1);

namespace App\Service\Bitmart\Private;

use App\Bitmart\Http\BitmartHttpClientPrivate;

final class AssetsService
{
    public function __construct(
        private readonly BitmartHttpClientPrivate $client,
    ) {}

    /** GET /contract/private/assets-detail */
    public function getAssetsDetail(): array
    {
        return $this->client->request('GET', '/contract/private/assets-detail');
    }
}


