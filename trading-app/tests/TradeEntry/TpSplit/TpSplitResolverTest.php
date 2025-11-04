<?php
declare(strict_types=1);

namespace App\Tests\TradeEntry\TpSplit;

use PHPUnit\Framework\TestCase;
use App\TradeEntry\TpSplit\TpSplitResolver;
use App\TradeEntry\TpSplit\Dto\TpSplitContext;
use App\TradeEntry\TpSplit\Strategy\NeutralSplitStrategy;
use App\TradeEntry\TpSplit\Strategy\VolatileSplitStrategy;
use App\TradeEntry\TpSplit\Strategy\StrongMomentumSplitStrategy;
use App\TradeEntry\TpSplit\Strategy\DoubtfulLateEntrySplitStrategy;

final class TpSplitResolverTest extends TestCase
{
    private TpSplitResolver $resolver;

    protected function setUp(): void
    {
        $strategies = [
            new StrongMomentumSplitStrategy(),
            new VolatileSplitStrategy(),
            new DoubtfulLateEntrySplitStrategy(),
            new NeutralSplitStrategy(),
        ];
        $this->resolver = new TpSplitResolver($strategies);
    }

    public function testNeutralSplit(): void
    {
        $ctx = new TpSplitContext(
            symbol: 'BTCUSDT',
            momentum: 'moyen',
            atrPct: 1.5,
            mtfValidCount: 2,
            pullbackClear: false,
            lateEntry: false,
        );
        $ratio = $this->resolver->resolve($ctx);
        $this->assertSame(0.50, $ratio);
    }

    public function testVolatileSplit(): void
    {
        $ctx = new TpSplitContext(
            symbol: 'BTCUSDT',
            momentum: 'fort',
            atrPct: 2.1,
            mtfValidCount: 1,
            pullbackClear: false,
            lateEntry: false,
        );
        $ratio = $this->resolver->resolve($ctx);
        $this->assertSame(0.60, $ratio);
    }

    public function testStrongMomentumSplit(): void
    {
        $ctx = new TpSplitContext(
            symbol: 'BTCUSDT',
            momentum: 'fort',
            atrPct: 1.0,
            mtfValidCount: 3,
            pullbackClear: true,
            lateEntry: false,
        );
        $ratio = $this->resolver->resolve($ctx);
        $this->assertSame(0.30, $ratio);
    }

    public function testDoubtfulLateEntrySplit(): void
    {
        $ctx = new TpSplitContext(
            symbol: 'BTCUSDT',
            momentum: 'faible',
            atrPct: 2.5,
            mtfValidCount: 1,
            pullbackClear: false,
            lateEntry: true,
        );
        $ratio = $this->resolver->resolve($ctx);
        $this->assertSame(0.70, $ratio);
    }

    public function testFallbackSplit(): void
    {
        $ctx = new TpSplitContext(
            symbol: 'BTCUSDT',
            momentum: 'moyen',
            atrPct: 0.8,
            mtfValidCount: 1,
            pullbackClear: false,
            lateEntry: false,
        );
        $ratio = $this->resolver->resolve($ctx);
        $this->assertSame(0.50, $ratio);
    }
}

