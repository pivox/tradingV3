<?php

namespace App\Provider\Bitmart\Http;

use Symfony\Component\Lock\LockFactory;

trait throttleBitmartRequestTrait
{
    // Ancien throttle simple (conservé pour compatibilité temporaire)
    const THROTTLE_SECONDS = 0.2; // 200ms entre requêtes (fallback)

    // Chemin legacy (non utilisé par le nouveau limiteur)
    private string $throttleStatePath;

    // Répertoire pour l'état des buckets (doit être initialisé par le client)
    private string $throttleDirPath;

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

    // ========= Nouveau limiteur: buckets par endpoint =========

    /**
     * Applique un throttle par bucket (sliding window) avec overrides ENV et backoff via headers.
     */
    private function throttleBucket(LockFactory $lockFactory, string $bucketKey, int $defaultLimit, float $defaultWindowSec): void
    {
        // S'assurer que le répertoire est prêt
        $this->ensureThrottleDir();

        // Résoudre la configuration effective (ENV > headers > defaults)
        [$limit, $windowSec] = $this->resolveEffectiveRate($bucketKey, $defaultLimit, $defaultWindowSec);

        $lock = $lockFactory->createLock('bitmart.throttle.' . $bucketKey, max(1.0, $windowSec));
        $lock->acquire(true);

        try {
            $now = microtime(true);
            $state = $this->readBucketState($bucketKey);

            // Backoff basé sur header reset si remaining <= 0 (optionnel, conservateur)
            $header = $state['header'] ?? [];
            $headerRemaining = isset($header['remaining']) ? (int)$header['remaining'] : null;
            $headerResetTs   = isset($header['reset_ts']) ? (float)$header['reset_ts'] : null;
            if ($headerRemaining !== null && $headerResetTs !== null) {
                if ($headerRemaining <= 0 && $now < $headerResetTs) {
                    $sleep = (int) max(0, round(($headerResetTs - $now) * 1_000_000));
                    if ($sleep > 0) {
                        usleep(min($sleep, 2_000_000)); // max 2s par cycle pour rester réactif
                        $now = microtime(true);
                    }
                }
            }

            // Nettoyage de la fenêtre glissante
            $reqTs = array_values(array_filter((array)($state['req_ts'] ?? []), function ($ts) use ($now, $windowSec) {
                return is_numeric($ts) && ($now - (float)$ts) <= $windowSec;
            }));

            // Tant qu'on dépasse la limite, attendre jusqu'à expiration de la requête la plus ancienne
            while (count($reqTs) >= $limit) {
                $oldest = (float) $reqTs[0];
                $sleepSec = ($oldest + $windowSec) - $now;
                if ($sleepSec > 0) {
                    usleep((int) round(min($sleepSec, 2.0) * 1_000_000)); // dormir par petits incréments
                    $now = microtime(true);
                } else {
                    break;
                }

                // Purger à nouveau après le sommeil
                $reqTs = array_values(array_filter($reqTs, function ($ts) use ($now, $windowSec) {
                    return is_numeric($ts) && ($now - (float)$ts) <= $windowSec;
                }));
            }

            // Enregistrer cette requête
            $reqTs[] = $now;
            $state['req_ts'] = $reqTs;
            $this->writeBucketState($bucketKey, $state);
        } finally {
            $lock->release();
        }
    }

    /**
     * Met à jour l'état du bucket d'après les headers Bitmart.
     * Utilise X-BM-RateLimit-Limit, X-BM-RateLimit-Reset, X-BM-RateLimit-Remaining si présents.
     */
    private function updateBucketFromHeaders(string $bucketKey, array $headers): void
    {
        $this->ensureThrottleDir();

        $limitStr = $this->findHeaderValue($headers, 'X-BM-RateLimit-Limit');
        $resetStr = $this->findHeaderValue($headers, 'X-BM-RateLimit-Reset');
        $remainStr = $this->findHeaderValue($headers, 'X-BM-RateLimit-Remaining');

        if ($limitStr === null && $resetStr === null && $remainStr === null) {
            return; // rien à faire
        }

        $now = microtime(true);
        $path = $this->bucketFilePath($bucketKey);
        $state = $this->readBucketState($bucketKey);

        $header = $state['header'] ?? [];

        if ($limitStr !== null && ctype_digit((string)trim($limitStr))) {
            $header['limit'] = (int) trim($limitStr);
        }

        if ($resetStr !== null && is_numeric(trim($resetStr))) {
            // Bitmart doc indique une fenêtre (ex: 60 sec). On calcule un reset_ts naïf.
            $windowSec = (float) trim($resetStr);
            $header['window_sec'] = $windowSec;
            $header['reset_ts'] = $now + $windowSec;
        }

        if ($remainStr !== null && is_numeric(trim($remainStr))) {
            $header['remaining'] = (int) trim($remainStr);
        }

        $state['header'] = $header;
        $this->writeBucketState($bucketKey, $state);
    }

    // ========= Helpers =========

    private function ensureThrottleDir(): void
    {
        if (!isset($this->throttleDirPath) || $this->throttleDirPath === '') {
            // Si non initialisé par le client, créer un fallback local dans sys_get_temp_dir
            $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bitmart_throttle';
            if (!is_dir($fallback)) {
                @mkdir($fallback, 0775, true);
            }
            $this->throttleDirPath = $fallback;
        }
        if (!is_dir($this->throttleDirPath)) {
            @mkdir($this->throttleDirPath, 0775, true);
        }
    }

    private function bucketFilePath(string $bucketKey): string
    {
        return rtrim($this->throttleDirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bucket_' . strtolower($bucketKey) . '.json';
    }

    /**
     * @return array{req_ts: array<int,float>, header: array<string,mixed>}
     */
    private function readBucketState(string $bucketKey): array
    {
        $path = $this->bucketFilePath($bucketKey);
        if (!is_file($path)) {
            return ['req_ts' => [], 'header' => []];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['req_ts' => [], 'header' => []];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['req_ts' => [], 'header' => []];
        }
        $data['req_ts'] = is_array($data['req_ts'] ?? null) ? $data['req_ts'] : [];
        $data['header'] = is_array($data['header'] ?? null) ? $data['header'] : [];
        return $data;
    }

    private function writeBucketState(string $bucketKey, array $state): void
    {
        $path = $this->bucketFilePath($bucketKey);
        @file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Calcule la configuration effective d'un bucket: ENV > headers précédents > defaults.
     * @return array{int,float} [limit, windowSec]
     */
    private function resolveEffectiveRate(string $bucketKey, int $defaultLimit, float $defaultWindowSec): array
    {
        // 1) ENV overrides
        $envLimit = null;
        $envWindow = null;
        $envKey = 'BITMART_RATE_' . strtoupper($bucketKey);
        $envCombined = getenv($envKey) ?: false;
        if (is_string($envCombined) && trim($envCombined) !== '') {
            $parsed = $this->parseEnvRate($envCombined);
            if ($parsed) {
                [$envLimit, $envWindow] = $parsed;
            }
        }
        $envLimitInd = getenv($envKey . '_LIMIT');
        $envWindowInd = getenv($envKey . '_WINDOW');
        if ($envLimit === null && is_string($envLimitInd) && ctype_digit(trim($envLimitInd))) {
            $envLimit = (int) trim($envLimitInd);
        }
        if ($envWindow === null && is_string($envWindowInd) && is_numeric(trim($envWindowInd))) {
            $envWindow = (float) trim($envWindowInd);
        }

        // Defaults de groupe (PUBLIC_DEFAULT / PRIVATE_DEFAULT)
        if ($envLimit === null || $envWindow === null) {
            $parts = explode('_', strtoupper($bucketKey), 2); // ex: PUBLIC_KLINE -> [PUBLIC, KLINE]
            $group = $parts[0] ?? 'PUBLIC';
            $groupKey = 'BITMART_RATE_' . $group . '_DEFAULT';
            $groupCombined = getenv($groupKey) ?: false;
            if (is_string($groupCombined) && trim($groupCombined) !== '') {
                $parsed = $this->parseEnvRate($groupCombined);
                if ($parsed) {
                    [$gLim, $gWin] = $parsed;
                    $envLimit = $envLimit ?? $gLim;
                    $envWindow = $envWindow ?? $gWin;
                }
            }
        }

        $limit = $envLimit ?? $defaultLimit;
        $windowSec = $envWindow ?? $defaultWindowSec;

        // 2) Header précédent (conservateur: min avec defaults/env)
        $state = $this->readBucketState($bucketKey);
        $header = $state['header'] ?? [];
        $hLimit = isset($header['limit']) && is_numeric($header['limit']) ? (int)$header['limit'] : null;
        $hWindow = isset($header['window_sec']) && is_numeric($header['window_sec']) ? (float)$header['window_sec'] : null;
        if ($hLimit !== null && $hWindow !== null && $hLimit > 0 && $hWindow > 0.0) {
            // comparer des budgets sur une même base de temps: appliquer min en rps effectif
            $effRps = $limit / $windowSec;
            $hdrRps = $hLimit / $hWindow;
            if ($hdrRps < $effRps) {
                $limit = $hLimit;
                $windowSec = $hWindow;
            }
        }

        return [$limit, $windowSec];
    }

    private function parseEnvRate(string $val): ?array
    {
        // format attendu: "limit/window", ex: "12/2"
        $val = trim($val);
        if ($val === '') {
            return null;
        }
        $parts = explode('/', $val);
        if (count($parts) !== 2) {
            return null;
        }
        [$l, $w] = $parts;
        if (!ctype_digit(trim($l)) || !is_numeric(trim($w))) {
            return null;
        }
        return [(int) trim($l), (float) trim($w)];
    }

    private function findHeaderValue(array $headers, string $name): ?string
    {
        $target = strtolower($name);
        foreach ($headers as $k => $vals) {
            $key = strtolower((string)$k);
            if ($key === $target) {
                if (is_array($vals) && isset($vals[0])) {
                    return is_array($vals[0]) ? (string) reset($vals[0]) : (string) $vals[0];
                }
                if (is_string($vals)) {
                    return $vals;
                }
            }
        }
        return null;
    }
}
