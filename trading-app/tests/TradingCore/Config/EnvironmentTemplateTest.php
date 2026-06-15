<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class EnvironmentTemplateTest extends TestCase
{
    public function testEnvironmentTemplatesDoNotContainSecretValues(): void
    {
        foreach (['dev.env', 'prod.env'] as $template) {
            $path = dirname(__DIR__, 4) . '/config_file/' . $template;
            self::assertFileExists($path);

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                self::assertMatchesRegularExpression('/^[A-Z0-9_]+=$/', $trimmed, sprintf(
                    '%s must only contain empty KEY= template entries; got "%s"',
                    $template,
                    $trimmed,
                ));
            }
        }
    }
}
