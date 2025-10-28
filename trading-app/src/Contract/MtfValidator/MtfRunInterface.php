<?php

namespace App\Contract\MtfValidator;

use App\Contract\MtfValidator\Dto\MtfRunDto;

interface MtfRunInterface
{
    public function run(MtfRunDto $mtfRunDto): \Generator;
}
