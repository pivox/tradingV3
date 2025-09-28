<?php

namespace App\Service\Temporal\Dto;

enum UrlType: string
{
    case GET_ALL_CONTRACTS = 'get all contracts';
    case GET_KLINE         = 'get kline';
}
