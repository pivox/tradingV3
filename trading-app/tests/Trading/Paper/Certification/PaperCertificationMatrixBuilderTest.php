<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Certification;

use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCertificationMatrixBuilder::class)]
final class PaperCertificationMatrixBuilderTest extends TestCase
{
    public function testBuildsTheTwelveExactExecutableMainnetCellsDeterministically(): void
    {
        $builder = new PaperCertificationMatrixBuilder(new ModeContractLoader(), new SetupContractLoader());

        $first = $builder->build($this->spec());
        $second = $builder->build($this->spec());

        self::assertSame($first, $second);
        self::assertSame('paper-certification-matrix-v1', $first['schema_version']);
        self::assertSame(50, $first['minimum_certified_trades_per_cell']);
        self::assertCount(12, $first['cells']);
        self::assertSame('sha256:' . hash('sha256', json_encode($first['cells'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $first['cells_sha256']);
        self::assertSame([
            'paper_network' => 'mainnet',
            'market_data_venue' => 'hyperliquid',
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'canonical_side' => 'long',
        ], $first['cells'][0]);
        self::assertSame([
            'paper_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'scalping.trend_momentum.short',
            'setup_version' => '1.1.0',
            'canonical_side' => 'short',
        ], $first['cells'][11]);
        self::assertSame([
            'day_trading' => 2,
            'micro_scalping' => 4,
            'scalping' => 6,
        ], $first['expected_cell_count_by_mode']);

        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('crash_short', $encoded);
        self::assertStringNotContainsString('day_trading.trend_continuation.short', $encoded);
        self::assertStringNotContainsString('regular', $encoded);
    }

    public function testRejectsUnsupportedOrDuplicateScopesFailClosed(): void
    {
        $builder = new PaperCertificationMatrixBuilder(new ModeContractLoader(), new SetupContractLoader());
        $spec = $this->spec();
        $spec['scopes'][] = $spec['scopes'][0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_certification_scope_duplicate');

        $builder->build($spec);
    }

    public function testRejectsAnIncompleteExactVersionMap(): void
    {
        $builder = new PaperCertificationMatrixBuilder(new ModeContractLoader(), new SetupContractLoader());
        $spec = $this->spec();
        unset($spec['setup_versions']['scalping.pullback.long']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_certification_setup_versions_incomplete');

        $builder->build($spec);
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return [
            'schema_version' => 'paper-certification-matrix-input-v1',
            'minimum_certified_trades_per_cell' => 50,
            'scopes' => [
                ['paper_network' => 'mainnet', 'market_data_venue' => 'okx'],
                ['paper_network' => 'mainnet', 'market_data_venue' => 'hyperliquid'],
            ],
            'mode_versions' => [
                'day_trading' => '1.1.0',
                'scalping' => '1.1.0',
                'micro_scalping' => '1.1.0',
            ],
            'setup_versions' => [
                'day_trading.trend_continuation.long' => '1.1.0',
                'day_trading.trend_continuation.short' => '1.0.0',
                'scalping.trend_continuation.long' => '1.1.0',
                'scalping.pullback.long' => '1.1.0',
                'scalping.trend_momentum.short' => '1.1.0',
                'micro_scalping.momentum_ofi.long' => '1.1.0',
                'micro_scalping.momentum_ofi.short' => '1.1.0',
            ],
        ];
    }
}
