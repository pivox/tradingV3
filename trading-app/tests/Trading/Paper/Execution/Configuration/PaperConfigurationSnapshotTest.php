<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Configuration;

use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperConfigurationSnapshot::class)]
#[CoversClass(PaperConfigurationSnapshotFactory::class)]
final class PaperConfigurationSnapshotTest extends TestCase
{
    public function testIdentityIsCanonicalAndContentAddressed(): void
    {
        $factory = new PaperConfigurationSnapshotFactory();
        $left = $factory->create([
            'strategy' => ['mode' => 'regular', 'thresholds' => ['slow' => 21, 'fast' => 9]],
            'symbols' => ['BTCUSDT', 'ETHUSDT'],
        ]);
        $right = $factory->create([
            'symbols' => ['BTCUSDT', 'ETHUSDT'],
            'strategy' => ['thresholds' => ['fast' => 9, 'slow' => 21], 'mode' => 'regular'],
        ]);

        self::assertSame($left->canonicalJson, $right->canonicalJson);
        self::assertSame($left->id, $right->id);
        self::assertSame('sha256:' . hash('sha256', $left->canonicalJson), $left->id);
        self::assertSame(1, $left->schemaVersion);
    }

    public function testScalarTypesAndListOrderRemainIdentitySignificant(): void
    {
        $factory = new PaperConfigurationSnapshotFactory();
        $integer = $factory->create(['risk' => ['leverage' => 2], 'symbols' => ['BTCUSDT', 'ETHUSDT']]);
        $string = $factory->create(['risk' => ['leverage' => '2'], 'symbols' => ['BTCUSDT', 'ETHUSDT']]);
        $reordered = $factory->create(['risk' => ['leverage' => 2], 'symbols' => ['ETHUSDT', 'BTCUSDT']]);

        self::assertNotSame($integer->id, $string->id);
        self::assertNotSame($integer->id, $reordered->id);
    }

    #[DataProvider('unsafeConfigurationProvider')]
    public function testUnsafeKeysAreRejectedRecursively(array $configuration): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_configuration_forbidden_key');

        (new PaperConfigurationSnapshotFactory())->create($configuration);
    }

    public static function unsafeConfigurationProvider(): iterable
    {
        yield 'api secret' => [['strategy' => ['nested' => ['api_secret' => 'value']]]];
        yield 'authorization token' => [['runtime' => ['AuthorizationToken' => 'value']]];
        yield 'wallet' => [['models' => ['wallet-address' => '0x123']]];
        yield 'signer' => [['execution' => ['signer' => 'remote']]];
        yield 'private key' => [['risk' => ['privateKey' => 'value']]];
    }

    public function testUnknownTopLevelSectionIsRejectedWithoutLeakingItsContent(): void
    {
        try {
            (new PaperConfigurationSnapshotFactory())->create(['credentials_dump' => ['secret' => 'do-not-log']]);
            self::fail('Unknown configuration section was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_configuration_section_not_allowed', $exception->getMessage());
            self::assertStringNotContainsString('do-not-log', $exception->getMessage());
        }
    }

    public function testSnapshotKeepsADetachedConfigurationValue(): void
    {
        $configuration = ['strategy' => ['mode' => 'regular']];
        $snapshot = (new PaperConfigurationSnapshotFactory())->create($configuration);
        $configuration['strategy']['mode'] = 'scalper';

        self::assertSame('regular', $snapshot->configuration['strategy']['mode']);
    }
}
