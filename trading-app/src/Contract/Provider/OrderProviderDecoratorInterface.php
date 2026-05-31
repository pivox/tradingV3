<?php

declare(strict_types=1);

namespace App\Contract\Provider;

interface OrderProviderDecoratorInterface extends OrderProviderInterface
{
    public function innerOrderProvider(): OrderProviderInterface;
}
