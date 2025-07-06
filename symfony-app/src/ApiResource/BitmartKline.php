<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Controller\Bitmart\KlineController;
use App\Dto\BitmartKlineInput;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/bitmart/kline',
            controller: KlineController::class,
            normalizationContext: ['openapi_definition_name' => 'Bitmart'],
            input: BitmartKlineInput::class,
            output: false,
            read: false,
            extraProperties: [
                'openapi_context' => [
                    'tags' => ['Bitmart'],
                    'summary' => 'Get Klines from BitMart (direct or persisted)',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['symbol'],
                                    'properties' => [
                                        'symbol' => [
                                            'type' => 'string',
                                            'description' => 'Trading pair symbol (e.g. BTCUSDT)'
                                        ],
                                        'start' => [
                                            'type' => 'string',
                                            'format' => 'date-time',
                                            'description' => 'Start date (ISO 8601 or timestamp)'
                                        ],
                                        'end' => [
                                            'type' => 'string',
                                            'format' => 'date-time',
                                            'description' => 'End date (ISO 8601 or timestamp)'
                                        ],
                                        'interval' => [
                                            'type' => 'string',
                                            'description' => 'Kline interval (e.g. 1m, 5m, 1h, 4h, 1d)',
                                            'default' => '15m',
                                        ],
                                        'persist' => [
                                            'type' => 'boolean',
                                            'default' => true,
                                            'description' => 'true = persist to DB, false = fetch only'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => 'Klines list from BitMart'],
                        '400' => ['description' => 'Invalid request'],
                        '500' => ['description' => 'Internal error']
                    ]
                ]
            ]
        )
    ],
    paginationEnabled: false
)]
final class BitmartKline
{
    public array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }
}
