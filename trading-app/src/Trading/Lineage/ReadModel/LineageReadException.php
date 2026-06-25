<?php

declare(strict_types=1);

namespace App\Trading\Lineage\ReadModel;

final class LineageReadException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        int $httpStatus,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
