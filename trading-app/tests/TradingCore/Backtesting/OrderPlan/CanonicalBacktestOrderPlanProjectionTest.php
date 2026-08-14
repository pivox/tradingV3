<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\OrderPlan;

use App\TradingCore\Backtesting\OrderPlan\CanonicalBacktestOrderPlanProjection;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalBacktestOrderPlanProjection::class)]
final class CanonicalBacktestOrderPlanProjectionTest extends TestCase
{
    public function testItProjectsAnExactHashBoundBacktestPlan(): void
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $plan = (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...CanonicalOrderPlanPipelineFixture::accepted()));

        $payload = (new CanonicalBacktestOrderPlanProjection())->project(
            $plan,
            datasetId: 'backtest-dataset-' . str_repeat('a', 64),
            datasetChecksum: 'sha256:' . str_repeat('a', 64),
            timeframe: '5m',
        );

        self::assertSame([
            'schema_version',
            'dataset_id',
            'dataset_checksum',
            'timeframe',
            'plan',
        ], array_keys($payload));
        self::assertSame('canonical-backtest-order-plan.v2', $payload['schema_version']);
        self::assertFalse($payload['plan']['marketFallback']);
        self::assertSame($plan->planHash, $payload['plan']['planHash']);
        self::assertSame($plan->expectedPlanHash(), $payload['plan']['planHash']);
        self::assertSame($plan->modeId, $payload['plan']['modeId']);
        self::assertSame($plan->targets[0]->toArray(), $payload['plan']['targets'][0]);
        self::assertSame(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES),
            (new CanonicalBacktestOrderPlanProjection())->canonicalJson($payload),
        );
    }
}
