<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Timeframe;

use App\Common\Enum\Timeframe;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.mtf.timeframe.processor')]
final class Timeframe1mService extends BaseTimeframeService
{
    public function getTimeframe(): Timeframe
    {
        return Timeframe::TF_1M;
    }
}