<?php

namespace App\Service\Temporal\Dto;

enum PrioInTemporal: string
{
    case URGENT = 'urgent';
    case POSITION_PRIOR = 'position_prior';
    case POSITION = 'position';
    case BALANCE = 'balance';
    case ONE_MINUTE = '1m';
    case FIVE_MINUTES = '5m';
    case FIFTEEN_MINUTES = '15m';
    case ONE_HOUR = '1h';
    case FOUR_HOURS = '4h';
    case ONE_MINUTE_CRON = '1m-cron';
    case FIVE_MINUTES_CRON = '5m-cron';
    case FIFTEEN_MINUTES_CRON = '15m-cron';
    case ONE_HOUR_CRON = '1h-cron';
    case FOUR_HOURS_CRON = '4h-cron';
    case REGULAR = 'regular';
}
