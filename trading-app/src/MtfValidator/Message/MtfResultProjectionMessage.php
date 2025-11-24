<?php

declare(strict_types=1);

namespace App\MtfValidator\Message;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;

final class MtfResultProjectionMessage
{
    public function __construct(
        public readonly string $runId,
        public readonly MtfRunDto $mtfRun,
        public readonly MtfResultDto $result,
    ) {
    }
}

