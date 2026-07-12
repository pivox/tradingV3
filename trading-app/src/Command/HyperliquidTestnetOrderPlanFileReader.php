<?php

declare(strict_types=1);

namespace App\Command;

final class HyperliquidTestnetOrderPlanFileReader implements HyperliquidTestnetOrderPlanFileReaderInterface
{
    private const MAX_FILE_BYTES = 65_536;
    private const REGULAR_FILE_TYPE = 0100000;
    private const FILE_TYPE_MASK = 0170000;

    /** @var list<string> */
    private const STABLE_FIELDS = ['dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime'];

    public function read(string $path): string
    {
        $before = $this->pathStat($path);
        $this->assertSafeOperatorFile($before);

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('order_plan_file_invalid');
        }

        $locked = false;
        try {
            $locked = flock($handle, \LOCK_SH | \LOCK_NB);
            if (!$locked) {
                throw new \InvalidArgumentException('order_plan_file_locked');
            }

            $opened = fstat($handle);
            if (!is_array($opened) || !$this->sameStableFile($before, $opened)) {
                throw new \InvalidArgumentException('order_plan_file_changed');
            }
            $this->assertSafeOperatorFile($opened);

            $contents = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            if (!is_string($contents) || strlen($contents) > self::MAX_FILE_BYTES || !feof($handle)) {
                throw new \InvalidArgumentException('order_plan_file_invalid');
            }

            $afterRead = fstat($handle);
            if (!is_array($afterRead) || !$this->sameStableFile($opened, $afterRead)) {
                throw new \InvalidArgumentException('order_plan_file_changed');
            }

            $afterPath = $this->pathStat($path);
            if (!$this->sameStableFile($afterRead, $afterPath)) {
                throw new \InvalidArgumentException('order_plan_file_changed');
            }
            $this->assertSafeOperatorFile($afterPath);

            return $contents;
        } finally {
            if ($locked) {
                flock($handle, \LOCK_UN);
            }
            fclose($handle);
        }
    }

    /** @return array<string,int> */
    private function pathStat(string $path): array
    {
        if ($path === '' || is_link($path)) {
            throw new \InvalidArgumentException('order_plan_file_invalid');
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            throw new \InvalidArgumentException('order_plan_file_invalid');
        }

        return $stat;
    }

    /** @param array<string,int> $stat */
    private function assertSafeOperatorFile(array $stat): void
    {
        $effectiveUserId = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (($stat['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE_TYPE
            || $effectiveUserId === null
            || $stat['uid'] !== $effectiveUserId
            || ($stat['mode'] & 0022) !== 0
            || $stat['size'] < 1
            || $stat['size'] > self::MAX_FILE_BYTES
        ) {
            throw new \InvalidArgumentException('order_plan_file_unsafe');
        }
    }

    /**
     * @param array<string,int> $left
     * @param array<string,int> $right
     */
    private function sameStableFile(array $left, array $right): bool
    {
        foreach (self::STABLE_FIELDS as $field) {
            if (!array_key_exists($field, $left)
                || !array_key_exists($field, $right)
                || $left[$field] !== $right[$field]
            ) {
                return false;
            }
        }

        return true;
    }
}
