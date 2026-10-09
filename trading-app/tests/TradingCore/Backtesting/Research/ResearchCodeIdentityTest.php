<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\Research\ResearchCodeIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchCodeIdentity::class)]
final class ResearchCodeIdentityTest extends TestCase
{
    public function testContentFingerprintCoversAppVendorConfigAndLocksWithoutMtimeCache(): void
    {
        $root = sys_get_temp_dir() . '/research-code-identity-' . bin2hex(random_bytes(8));
        $directories = [$root, "$root/src", "$root/vendor", "$root/config", "$root/bin"];
        $files = [
            'src/CanonicalBacktestRuleEvaluator.php' => '<?php return 1;',
            'vendor/CanonicalInstrumentSnapshot.php' => '<?php return 1;',
            'config/services.yaml' => 'services: one',
            'composer.json' => '{}',
            'composer.lock' => '{}',
            'symfony.lock' => '{}',
            'bin/console' => '<?php return 1;',
        ];
        try {
            foreach ($directories as $directory) {
                self::assertTrue(mkdir($directory));
            }
            foreach ($files as $relative => $contents) {
                self::assertSame(strlen($contents), file_put_contents("$root/$relative", $contents));
            }
            $before = ResearchCodeIdentity::current($root);
            $source = "$root/src/CanonicalBacktestRuleEvaluator.php";
            $mtime = filemtime($source);
            self::assertIsInt($mtime);
            file_put_contents($source, '<?php return 2;');
            touch($source, $mtime);
            clearstatcache(true, $source);
            $afterSource = ResearchCodeIdentity::current($root);
            self::assertNotSame($before, $afterSource);

            file_put_contents("$root/vendor/CanonicalInstrumentSnapshot.php", '<?php return 2;');
            $afterVendor = ResearchCodeIdentity::current($root);
            self::assertNotSame($afterSource, $afterVendor);
            file_put_contents("$root/config/services.yaml", 'services: two');
            $afterConfig = ResearchCodeIdentity::current($root);
            self::assertNotSame($afterVendor, $afterConfig);
            file_put_contents("$root/composer.lock", '{"v":2}');
            $afterLock = ResearchCodeIdentity::current($root);
            self::assertNotSame($afterConfig, $afterLock);
            $files['src/StrictJsonObjectDecoder.php'] = '<?php return 1;';
            file_put_contents("$root/src/StrictJsonObjectDecoder.php", $files['src/StrictJsonObjectDecoder.php']);
            self::assertNotSame($afterLock, ResearchCodeIdentity::current($root));
        } finally {
            foreach (array_keys($files) as $relative) {
                if (is_file("$root/$relative")) {
                    unlink("$root/$relative");
                }
            }
            foreach (array_reverse($directories) as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
    }
}
