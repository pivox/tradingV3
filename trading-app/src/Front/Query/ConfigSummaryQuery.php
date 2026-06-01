<?php

declare(strict_types=1);

namespace App\Front\Query;

use Symfony\Component\Yaml\Yaml;

final class ConfigSummaryQuery
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'modes' => $this->modes(),
            'files' => $this->configFiles(),
            'active_mode' => $this->activeMode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activeMode(): array
    {
        $modes = $this->modes();
        $enabled = array_values(array_filter($modes, static fn (array $mode): bool => (bool) ($mode['enabled'] ?? false)));
        usort($enabled, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

        return $enabled[0] ?? ['name' => 'unknown', 'enabled' => false, 'priority' => null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modes(): array
    {
        $path = $this->projectDir . '/config/services.yaml';
        if (!is_file($path)) {
            return [];
        }

        $data = Yaml::parseFile($path);
        $modes = $data['parameters']['mode'] ?? [];

        if (!is_array($modes)) {
            return [];
        }

        return array_values(array_map(fn (mixed $mode): array => $this->normalizeMode($mode), $modes));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configFiles(): array
    {
        $files = array_merge(
            glob($this->projectDir . '/config/app/*.yaml') ?: [],
            glob($this->projectDir . '/src/MtfValidator/config/*.yaml') ?: [],
        );
        sort($files);

        return array_map(fn (string $path): array => [
            'path' => str_replace($this->projectDir . '/', '', $path),
            'name' => basename($path),
            'updated_at' => date('Y-m-d H:i:s', (int) filemtime($path)),
            'size_kb' => round(filesize($path) / 1024, 1),
        ], $files);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMode(mixed $mode): array
    {
        if (!is_array($mode)) {
            return ['name' => (string) $mode, 'enabled' => false, 'priority' => 0];
        }

        if (array_key_exists('name', $mode)) {
            return $mode;
        }

        $normalized = [];
        foreach ($mode as $part) {
            if (is_array($part)) {
                $normalized = array_replace($normalized, $part);
            }
        }

        return [
            'name' => (string) ($normalized['name'] ?? 'unknown'),
            'enabled' => (bool) ($normalized['enabled'] ?? false),
            'priority' => (int) ($normalized['priority'] ?? 0),
        ];
    }
}
