<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists per-mode zone_max_deviation_pct overrides so analyzer outputs can feed the execution config.
 */
final class ZoneDeviationOverrideStore
{
    private readonly string $path;

    /** @var array<string,array<string,array<string,mixed>>> */
    private array $data = [];

    private bool $dirty = false;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $this->path = rtrim($projectDir, '/') . '/var/config/zone_deviation_overrides.json';
        $this->load();
    }

    public function getOverride(?string $mode, string $symbol): ?float
    {
        $modeKey = $this->normalizeMode($mode);
        $symbolKey = strtoupper($symbol);
        $entry = $this->data[$modeKey][$symbolKey]['value'] ?? null;

        return $entry !== null ? (float) $entry : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function getMetadata(?string $mode, string $symbol): array
    {
        $modeKey = $this->normalizeMode($mode);
        $symbolKey = strtoupper($symbol);

        return $this->data[$modeKey][$symbolKey] ?? [];
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function setOverride(?string $mode, string $symbol, float $value, array $meta = []): void
    {
        $modeKey = $this->normalizeMode($mode);
        $symbolKey = strtoupper($symbol);

        $payload = array_merge([
            'value' => $value,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'source' => $meta['source'] ?? 'auto',
        ], $meta);
        $payload['value'] = $value;

        $this->data[$modeKey][$symbolKey] = $payload;
        $this->dirty = true;
    }

    public function removeOverride(?string $mode, string $symbol): void
    {
        $modeKey = $this->normalizeMode($mode);
        $symbolKey = strtoupper($symbol);
        if (!isset($this->data[$modeKey][$symbolKey])) {
            return;
        }

        unset($this->data[$modeKey][$symbolKey]);
        if ($this->data[$modeKey] === []) {
            unset($this->data[$modeKey]);
        }
        $this->dirty = true;
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function all(?string $mode = null): array
    {
        if ($mode === null) {
            return $this->data;
        }

        $modeKey = $this->normalizeMode($mode);

        return $this->data[$modeKey] ?? [];
    }

    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create override directory: %s', $dir));
            }
        }

        $encoded = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->path, $encoded);
        $this->dirty = false;
    }

    private function load(): void
    {
        if (!is_file($this->path)) {
            $this->data = [];
            return;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            $this->data = [];
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->data = [];
            return;
        }

        $this->data = $decoded;
    }

    private function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) ($mode ?? '')));

        return $mode === '' || $mode === 'default' ? 'default' : $mode;
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (\Throwable) {
            // Destructors must not throw; swallow to avoid fatal errors during shutdown.
        }
    }
}
