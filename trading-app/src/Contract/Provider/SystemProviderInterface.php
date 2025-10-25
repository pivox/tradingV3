<?php

namespace App\Contract\Provider;

interface SystemProviderInterface
{
    public function getSystemTimeMs(): int;
}
