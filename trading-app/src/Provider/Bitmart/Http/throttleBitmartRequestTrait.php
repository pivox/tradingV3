<?php

namespace App\Provider\Bitmart\Http;

use Symfony\Component\Lock\LockFactory;

trait throttleBitmartRequestTrait
{
    const THROTTLE_SECONDS = 0.2; // 200ms entre requêtes

    private string $throttleStatePath;

    private function throttleBitmartRequest(LockFactory $lockFactory): void
    {
        $lock = $lockFactory->createLock('bitmart.throttle', 1.0);
        $lock->acquire(true);

        try {
            $now = microtime(true);
            $lastRequest = 0.0;

            if (is_file($this->throttleStatePath)) {
                $raw = trim((string) @file_get_contents($this->throttleStatePath));
                if ($raw !== '') {
                    $lastRequest = (float) $raw;
                }
            }

            if ($lastRequest > 0.0) {
                $elapsed = $now - $lastRequest;
                if ($elapsed < self::THROTTLE_SECONDS) {
                    usleep((int) round((self::THROTTLE_SECONDS - $elapsed) * 1_000_000));
                    $now = microtime(true);
                }
            }

            @file_put_contents($this->throttleStatePath, sprintf('%.6F', $now));
        } finally {
            $lock->release();
        }
    }
}
