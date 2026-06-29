<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\SystemProviderInterface;

final class OkxSystemProvider implements SystemProviderInterface
{
    public function getSystemTimeMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
