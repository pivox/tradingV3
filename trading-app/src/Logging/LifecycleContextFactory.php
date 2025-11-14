<?php

declare(strict_types=1);

namespace App\Logging;

use App\Logging\Dto\LifecycleContextBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class LifecycleContextFactory
{
    public function __construct(
        #[Autowire('%env(default::APP_VERSION)%')]
        private readonly ?string $appVersion = null,
        #[Autowire('%env(default::WORKER_ID)%')]
        private readonly ?string $workerId = null,
    ) {
    }

    public function create(string $symbol): LifecycleContextBuilder
    {
        $worker = $this->workerId;
        if ($worker === null || $worker === '') {
            $worker = gethostname() ?: null;
        }

        $version = $this->appVersion;
        if ($version === null || $version === '') {
            try {
                $root = \Composer\InstalledVersions::getRootPackage();
                $version = $root['pretty_version'] ?? 'dev';
            } catch (\Throwable) {
                $version = 'dev';
            }
        }

        return new LifecycleContextBuilder($symbol, $worker, $version);
    }
}
