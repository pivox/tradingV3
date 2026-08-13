<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BacktestEvaluateCanonicalRulesCommand;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluation;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BacktestEvaluateCanonicalRulesCommand::class)]
final class BacktestEvaluateCanonicalRulesCommandTest extends TestCase
{
    public function testItWritesExactlyOneCompactResultToStdoutAndNothingToStderr(): void
    {
        $observed = null;
        $evaluator = $this->evaluator(static function (array $request) use (&$observed): CanonicalBacktestRuleEvaluation {
            $observed = $request;

            return self::evaluation(true, 'setup_rules_passed');
        });
        $tester = $this->runCommand('{"schema_version":"canonical-backtest-rule-request.v1","request_id":"request-1"}', $evaluator);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'schema_version' => 'canonical-backtest-rule-request.v1',
            'request_id' => 'request-1',
        ], $observed);
        self::assertSame(
            json_encode(self::evaluation(true, 'setup_rules_passed')->toArray(), self::jsonFlags()) . "\n",
            $tester->getDisplay(),
        );
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testNoTradeIsAlsoAProtocolSuccess(): void
    {
        $tester = $this->runCommand(
            '{"schema_version":"canonical-backtest-rule-request.v1"}',
            $this->evaluator(static fn (array $request): CanonicalBacktestRuleEvaluation => self::evaluation(false, 'no_trade_rule_matched')),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('"passed":false', $tester->getDisplay());
        self::assertStringContainsString('"reason_code":"no_trade_rule_matched"', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testEmptyJsonObjectReachesEvaluatorAsAnObjectPayload(): void
    {
        $calls = 0;
        $tester = $this->runCommand('{}', $this->evaluator(
            static function (array $request) use (&$calls): CanonicalBacktestRuleEvaluation {
                ++$calls;
                self::assertSame([], $request);

                return self::evaluation(false, 'canonical_request_shape_invalid');
            },
        ));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $calls);
    }

    #[DataProvider('invalidInputProvider')]
    public function testItRejectsMalformedAmbiguousOrNonObjectInputWithoutCallingEvaluator(
        string $label,
        string $payload,
        string $reason,
    ): void {
        $calls = 0;
        $tester = $this->runCommand($payload, $this->evaluator(static function (array $request) use (&$calls): CanonicalBacktestRuleEvaluation {
            ++$calls;

            return self::evaluation(true, 'unexpected');
        }));

        self::assertSame(Command::INVALID, $tester->getStatusCode(), $label);
        self::assertSame('', $tester->getDisplay(), $label);
        self::assertSame('canonical_backtest_rule_command_invalid:' . $reason . "\n", $tester->getErrorOutput(), $label);
        self::assertSame(0, $calls, $label);
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'blank' => ['blank', " \n\t", 'input_blank'];
        yield 'malformed' => ['malformed', '{"a":', 'json_invalid'];
        yield 'multiple documents' => ['multiple documents', '{} {}', 'json_invalid'];
        yield 'trailing token' => ['trailing token', '{}x', 'json_invalid'];
        yield 'list root' => ['list root', '[]', 'root_object_required'];
        yield 'scalar root' => ['scalar root', 'true', 'root_object_required'];
        yield 'duplicate root key' => ['duplicate root key', '{"a":1,"a":2}', 'duplicate_object_key'];
        yield 'duplicate nested key' => ['duplicate nested key', '{"a":{"b":1,"b":2}}', 'duplicate_object_key'];
        yield 'invalid utf8' => ['invalid utf8', "{\"a\":\"\xFF\"}", 'json_invalid'];
    }

    public function testItRejectsInputLargerThanEightMebibytesBeforeEvaluation(): void
    {
        $calls = 0;
        $payload = '{"payload":"' . str_repeat('x', 8_388_608) . '"}';
        $tester = $this->runCommand($payload, $this->evaluator(static function (array $request) use (&$calls): CanonicalBacktestRuleEvaluation {
            ++$calls;

            return self::evaluation(true, 'unexpected');
        }));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame("canonical_backtest_rule_command_invalid:input_too_large\n", $tester->getErrorOutput());
        self::assertSame(0, $calls);
    }

    public function testItAcceptsInputAtExactlyEightMebibytes(): void
    {
        $prefix = '{"payload":"';
        $suffix = '"}';
        $payload = $prefix . str_repeat('x', 8_388_608 - strlen($prefix) - strlen($suffix)) . $suffix;
        $tester = $this->runCommand(
            $payload,
            $this->evaluator(static fn (array $request): CanonicalBacktestRuleEvaluation => self::evaluation(true, 'setup_rules_passed')),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testItRejectsPathologicallyWideJsonBeforeDecodeOrEvaluation(): void
    {
        $members = [];
        for ($index = 0; $index < 20_001; ++$index) {
            $members[] = '"k' . $index . '":0';
        }
        $payload = '{' . implode(',', $members) . '}';
        self::assertLessThan(8_388_608, strlen($payload));
        $calls = 0;
        $tester = $this->runCommand($payload, $this->evaluator(
            static function (array $request) use (&$calls): CanonicalBacktestRuleEvaluation {
                ++$calls;

                return self::evaluation(true, 'unexpected');
            },
        ));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "canonical_backtest_rule_command_invalid:json_structure_too_large\n",
            $tester->getErrorOutput(),
        );
        self::assertSame(0, $calls);
    }

    public function testItRejectsJsonBeyondDepth128BeforeEvaluation(): void
    {
        $payload = str_repeat('{"a":', 129) . 'null' . str_repeat('}', 129);
        $tester = $this->runCommand($payload, $this->evaluator(
            static fn (array $request): CanonicalBacktestRuleEvaluation => self::evaluation(true, 'unexpected'),
        ));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame("canonical_backtest_rule_command_invalid:json_depth_exceeded\n", $tester->getErrorOutput());
    }

    public function testEvaluatorFailureIsInvalidWithStderrOnlyAndNoSensitivePayloadEcho(): void
    {
        $secret = 'do-not-echo-this';
        $tester = $this->runCommand(
            '{"secret":"' . $secret . '"}',
            $this->evaluator(static function (array $request): CanonicalBacktestRuleEvaluation {
                throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_mismatch');
            }),
        );

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "canonical_backtest_rule_command_invalid:canonical_backtest_rule_snapshot_mismatch\n",
            $tester->getErrorOutput(),
        );
        self::assertStringNotContainsString($secret, $tester->getErrorOutput());
    }

    public function testReaderFailureIsInvalidAndNeverWritesStdout(): void
    {
        $command = new BacktestEvaluateCanonicalRulesCommand(
            $this->evaluator(static fn (array $request): CanonicalBacktestRuleEvaluation => self::evaluation(true, 'unexpected')),
            static function (): string {
                throw new \RuntimeException('reader exploded');
            },
        );
        $tester = new CommandTester($command);
        $status = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame('', $tester->getDisplay());
        self::assertSame("canonical_backtest_rule_command_invalid:input_read_failed\n", $tester->getErrorOutput());
    }

    private function runCommand(string $payload, CanonicalBacktestRuleEvaluatorInterface $evaluator): CommandTester
    {
        $tester = new CommandTester(new BacktestEvaluateCanonicalRulesCommand(
            $evaluator,
            static fn (): string => $payload,
        ));
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    /** @param \Closure(array<string,mixed>):CanonicalBacktestRuleEvaluation $callback */
    private function evaluator(\Closure $callback): CanonicalBacktestRuleEvaluatorInterface
    {
        return new class($callback) implements CanonicalBacktestRuleEvaluatorInterface {
            /** @param \Closure(array<string,mixed>):CanonicalBacktestRuleEvaluation $callback */
            public function __construct(private readonly \Closure $callback) {}

            public function evaluate(array $request): CanonicalBacktestRuleEvaluation
            {
                return ($this->callback)($request);
            }
        };
    }

    private static function evaluation(bool $passed, string $reason): CanonicalBacktestRuleEvaluation
    {
        return new CanonicalBacktestRuleEvaluation([
            'schema_version' => 'canonical-backtest-rule-result.v1',
            'request_id' => 'request-1',
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'scalping.pullback.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'config_hash' => 'sha256:' . str_repeat('a', 64),
            'condition_catalog_hash' => 'sha256:' . str_repeat('b', 64),
            'snapshot_hash' => 'sha256:' . str_repeat('c', 64),
            'evaluated_at' => '2026-08-10T12:00:00Z',
            'passed' => $passed,
            'reason_code' => $reason,
            'trace' => ['plan_cache_key' => str_repeat('d', 64)],
            'input_hash' => 'sha256:' . str_repeat('e', 64),
            'result_hash' => 'sha256:' . str_repeat('f', 64),
        ]);
    }

    private static function jsonFlags(): int
    {
        return JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    }
}
