<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    public function testFakeCompatibilityKeysArePresentInEnvironmentTemplates(): void
    {
        $projectRoot = dirname(__DIR__, 4);
        $fakeConfig = Yaml::parseFile($projectRoot . '/trading-app/config/trading/exchange/fake.yaml');
        self::assertIsArray($fakeConfig);

        $requiredKeys = $fakeConfig['compatibility']['env_template_keys'] ?? null;
        self::assertIsArray($requiredKeys);

        foreach (['dev.env', 'prod.env'] as $template) {
            $lines = file($projectRoot . '/config_file/' . $template, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);

            $templateKeys = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                $key = strstr($trimmed, '=', true);
                self::assertIsString($key);
                $templateKeys[$key] = true;
            }

            foreach ($requiredKeys as $requiredKey) {
                self::assertIsString($requiredKey);
                self::assertArrayHasKey($requiredKey, $templateKeys, sprintf(
                    '%s must include Fake/Paper compatibility key %s=',
                    $template,
                    $requiredKey,
                ));
            }
        }
    }
}
