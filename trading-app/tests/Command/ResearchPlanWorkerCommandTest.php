<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ResearchPlanWorkerCommand;
use App\Tests\TradingCore\Backtesting\Research\ResearchPlanSessionTest;
use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use App\TradingCore\Backtesting\Research\ResearchPlanSession;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResearchPlanWorkerCommandTest extends TestCase
{
    public function testPersistentOpenAndDeclaredCloseWithoutDatabase(): void
    {
        [$open] = ResearchPlanSessionTest::frames();
        $open['expected_signals'] = 0;
        $close = ['schema_version' => 'research-plan-close.v1', 'session_id' => 'plan-reference', 'expected_signals' => 0];
        $tester = new CommandTester(new ResearchPlanWorkerCommand(
            new ResearchPlanSession(new EffectiveTradingConfigResolver()), new StrictJsonObjectDecoder(),
            self::stream([json_encode($open, JSON_THROW_ON_ERROR), json_encode($close, JSON_THROW_ON_ERROR)]),
        ));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $lines = array_values(array_filter(explode("\n", trim($tester->getDisplay()))));
        self::assertCount(2, $lines);
        self::assertSame('research-plan-opened.v1', json_decode($lines[0], true, 128, JSON_THROW_ON_ERROR)['schema_version']);
        self::assertSame('research-plan-summary.v1', json_decode($lines[1], true, 128, JSON_THROW_ON_ERROR)['schema_version']);
    }

    public function testWorkerRequiresExplicitClose(): void
    {
        [$open] = ResearchPlanSessionTest::frames();
        $open['expected_signals'] = 0;
        $tester = new CommandTester(new ResearchPlanWorkerCommand(
            new ResearchPlanSession(new EffectiveTradingConfigResolver()), new StrictJsonObjectDecoder(),
            self::stream([json_encode($open, JSON_THROW_ON_ERROR)]),
        ));
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('research_plan_explicit_close_required', $tester->getDisplay());
    }

    /** @param list<string> $lines */
    private static function stream(array $lines): \Closure
    {
        return static function () use ($lines) {
            $stream = fopen('php://memory', 'w+');
            foreach ($lines as $line) {
                fwrite($stream, $line . "\n");
            }
            rewind($stream);
            return $stream;
        };
    }
}
