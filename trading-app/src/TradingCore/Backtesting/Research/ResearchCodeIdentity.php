<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;

/** Content identity for the frozen application and installed dependencies. */
final class ResearchCodeIdentity
{
    public const SCOPE = 'research_plan_app_php_vendor_config_content_v2';
    public const CHECK_POLICY = 'content_at_open_and_close_immutable_appdir_during_session';

    public static function current(?string $appRoot = null): string
    {
        $appRoot ??= dirname(__DIR__, 4);
        $digests = [];
        foreach (['src', 'vendor', 'config'] as $directory) {
            $path = $appRoot . '/' . $directory;
            if (!is_dir($path) || is_link($path)) {
                throw new \InvalidArgumentException('research_plan_code_file_missing');
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink() || !$file->isFile()) {
                    throw new \InvalidArgumentException('research_plan_code_file_missing');
                }
                if ($directory === 'src' && $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($appRoot) + 1);
                $digest = hash_file('sha256', $file->getPathname());
                if ($digest === false) {
                    throw new \InvalidArgumentException('research_plan_code_file_missing');
                }
                $digests[$relative] = $digest;
            }
        }
        foreach (['bin/console', 'composer.json', 'composer.lock', 'symfony.lock'] as $relative) {
            $path = $appRoot . '/' . $relative;
            if (!is_file($path) || is_link($path)) {
                throw new \InvalidArgumentException('research_plan_code_file_missing');
            }
            $digest = hash_file('sha256', $path);
            if ($digest === false) {
                throw new \InvalidArgumentException('research_plan_code_file_missing');
            }
            $digests[$relative] = $digest;
        }
        return CanonicalBacktestRuleEvaluator::canonicalHash(['scope' => self::SCOPE, 'files' => $digests]);
    }
}
