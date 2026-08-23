<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

final class PaperCertificationCampaignStateStore
{
    private const MAX_BYTES = 4_194_304;

    /** @return array<string, mixed>|null */
    public function load(#[\SensitiveParameter] string $path): ?array
    {
        $this->assertPath($path);
        if (!file_exists($path)) {
            return null;
        }
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0
            || $stat['size'] < 2 || $stat['size'] > self::MAX_BYTES
        ) {
            throw new \RuntimeException('paper_campaign_state_invalid');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_campaign_state_invalid');
        }
        try {
            $opened = fstat($handle);
            $contents = stream_get_contents($handle);
            $after = @lstat($path);
            if ($opened === false
                || !is_string($contents)
                || strlen($contents) !== $stat['size']
                || $opened['dev'] !== $stat['dev']
                || $opened['ino'] !== $stat['ino']
                || $opened['size'] !== $stat['size']
                || $after === false
                || $after['dev'] !== $opened['dev']
                || $after['ino'] !== $opened['ino']
                || $after['size'] !== $opened['size']
            ) {
                throw new \RuntimeException('paper_campaign_state_invalid');
            }
        } finally {
            fclose($handle);
        }
        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('paper_campaign_state_invalid');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('paper_campaign_state_invalid');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $state */
    public function save(#[\SensitiveParameter] string $path, array $state): void
    {
        $this->assertPath($path);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new \RuntimeException('paper_campaign_state_too_large');
        }
        $temporary = tempnam(dirname($path), '.paper-campaign-');
        if (!is_string($temporary)) {
            throw new \RuntimeException('paper_campaign_state_write_failed');
        }
        try {
            if (!chmod($temporary, 0600)
                || file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)
                || !rename($temporary, $path)
            ) {
                throw new \RuntimeException('paper_campaign_state_write_failed');
            }
            chmod($path, 0600);
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function assertPath(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) || basename($path) !== basename(trim($path))) {
            throw new \InvalidArgumentException('paper_campaign_state_path_invalid');
        }
        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent) || realpath($parent) !== $parent || is_link($path)) {
            throw new \RuntimeException('paper_campaign_state_path_invalid');
        }
        $current = DIRECTORY_SEPARATOR;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $parent)) as $part) {
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new \RuntimeException('paper_campaign_state_path_invalid');
            }
        }
    }
}
