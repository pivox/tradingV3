<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

final class OkxDemoWriteRefusedException extends \RuntimeException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(public readonly string $action, public readonly array $reasons)
    {
        parent::__construct(sprintf('okx_demo_write_refused:%s:%s', $action, implode(',', $reasons)));
    }
}
