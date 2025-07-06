<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Controller\Bitmart\ContractsController;
use App\Dto\BitmartContractOutput;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/bitmart/contracts',
            controller: ContractsController::class,
            output: BitmartContractOutput::class,
            read: false,
            extraProperties: [
                'openapi_context' => [
                    'summary' => 'Fetch and persist all BitMart contracts',
                    'description' => 'This endpoint fetches all available contracts from BitMart and persists them in the database.',
                    'tags' => ['Bitmart'],
                    'responses' => [
                        '200' => ['description' => 'Contracts list from BitMart'],
                        '500' => ['description' => 'Internal error during fetch or persist'],
                    ],
                ]
            ]
        )
    ],
    paginationEnabled: false
)]
final class BitmartContractFetch
{
}
