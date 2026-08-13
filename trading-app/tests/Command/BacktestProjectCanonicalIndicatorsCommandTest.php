<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BacktestProjectCanonicalIndicatorsCommand;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectorInterface;
use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BacktestProjectCanonicalIndicatorsCommand::class)]
final class BacktestProjectCanonicalIndicatorsCommandTest extends TestCase
{
    public function testItWritesExactlyOneCompactProjectionToStdoutAndNothingToStderr(): void
    {
        $observed = null;
        $projection = self::projection();
        $tester = $this->runCommand(
            '{"schema_version":"canonical-indicator-projection-request.v1","request_id":"request-1"}',
            $this->projector(static function (array $request) use (&$observed, $projection): CanonicalIndicatorProjection {
                $observed = $request;

                return $projection;
            }),
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'request-1',
        ], $observed);
        self::assertSame(json_encode($projection->toArray(), self::jsonFlags()) . "\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame(1, substr_count($tester->getDisplay(), "\n"));
    }

    public function testRepeatedInputProducesByteIdenticalStdout(): void
    {
        $projector = $this->projector(static fn (array $request): CanonicalIndicatorProjection => self::projection());
        $first = $this->runCommand('{"request_id":"request-1"}', $projector);
        $second = $this->runCommand('{"request_id":"request-1"}', $projector);

        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertSame($first->getDisplay(), $second->getDisplay());
        self::assertSame('', $first->getErrorOutput());
        self::assertSame('', $second->getErrorOutput());
    }

    #[DataProvider('invalidInputProvider')]
    public function testItRejectsInvalidInputWithoutCallingProjector(
        string $label,
        string $payload,
        string $reason,
    ): void {
        $calls = 0;
        $tester = $this->runCommand($payload, $this->projector(
            static function (array $request) use (&$calls): CanonicalIndicatorProjection {
                ++$calls;

                return self::projection();
            },
        ));

        self::assertSame(Command::INVALID, $tester->getStatusCode(), $label);
        self::assertSame('', $tester->getDisplay(), $label);
        self::assertSame(
            'canonical_indicator_projection_command_invalid:' . $reason . "\n",
            $tester->getErrorOutput(),
            $label,
        );
        self::assertSame(0, $calls, $label);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'blank' => ['blank', " \n\t", 'input_blank'];
        yield 'malformed' => ['malformed', '{"a":', 'json_invalid'];
        yield 'multiple documents' => ['multiple documents', '{} {}', 'json_invalid'];
        yield 'list root' => ['list root', '[]', 'root_object_required'];
        yield 'scalar root' => ['scalar root', 'true', 'root_object_required'];
        yield 'duplicate key' => ['duplicate key', '{"a":1,"a":2}', 'duplicate_object_key'];
        yield 'invalid utf8' => ['invalid utf8', "{\"a\":\"\xFF\"}", 'json_invalid'];
    }

    public function testItRejectsOversizedInputBeforeCallingProjector(): void
    {
        $calls = 0;
        $tester = $this->runCommand(str_repeat('x', 8_388_609), $this->projector(
            static function (array $request) use (&$calls): CanonicalIndicatorProjection {
                ++$calls;

                return self::projection();
            },
        ));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "canonical_indicator_projection_command_invalid:input_too_large\n",
            $tester->getErrorOutput(),
        );
        self::assertSame(0, $calls);
    }

    public function testStableProjectorFailureIsInvalidWithStderrOnly(): void
    {
        $tester = $this->runCommand('{}', $this->projector(
            static function (array $request): CanonicalIndicatorProjection {
                throw new \InvalidArgumentException('canonical_indicator_window_hash_mismatch');
            },
        ));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "canonical_indicator_projection_command_invalid:canonical_indicator_window_hash_mismatch\n",
            $tester->getErrorOutput(),
        );
    }

    public function testUnexpectedProjectorFailureIsSanitized(): void
    {
        $secret = 'do-not-echo-this';
        $tester = $this->runCommand('{"secret":"' . $secret . '"}', $this->projector(
            static function (array $request) use ($secret): CanonicalIndicatorProjection {
                throw new \RuntimeException('projector exploded with ' . $secret);
            },
        ));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "canonical_indicator_projection_command_invalid:projection_failed\n",
            $tester->getErrorOutput(),
        );
        self::assertStringNotContainsString($secret, $tester->getErrorOutput());
    }

    public function testReaderFailureIsInvalidAndNeverWritesStdout(): void
    {
        $command = new BacktestProjectCanonicalIndicatorsCommand(
            $this->projector(static fn (array $request): CanonicalIndicatorProjection => self::projection()),
            new StrictJsonObjectDecoder(),
            static function (): string {
                throw new \RuntimeException('reader exploded');
            },
        );
        $tester = new CommandTester($command);
        $status = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "canonical_indicator_projection_command_invalid:input_read_failed\n",
            $tester->getErrorOutput(),
        );
    }

    private function runCommand(string $payload, CanonicalIndicatorProjectorInterface $projector): CommandTester
    {
        $tester = new CommandTester(new BacktestProjectCanonicalIndicatorsCommand(
            $projector,
            new StrictJsonObjectDecoder(),
            static fn (): string => $payload,
        ));
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    /** @param \Closure(array<string,mixed>):CanonicalIndicatorProjection $callback */
    private function projector(\Closure $callback): CanonicalIndicatorProjectorInterface
    {
        return new class($callback) implements CanonicalIndicatorProjectorInterface {
            /** @param \Closure(array<string,mixed>):CanonicalIndicatorProjection $callback */
            public function __construct(private readonly \Closure $callback) {}

            public function project(#[\SensitiveParameter] array $request): CanonicalIndicatorProjection
            {
                return ($this->callback)($request);
            }
        };
    }

    private static function projection(): CanonicalIndicatorProjection
    {
        return CanonicalIndicatorProjection::fromValidatedRequest([
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'request-1',
            'evaluated_at' => '2026-08-10T12:00:00.000000Z',
            'environment' => 'test',
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => [
                'dataset_id' => 'backtest-dataset-' . str_repeat('a', 64),
                'dataset_checksum' => 'sha256:' . str_repeat('a', 64),
                'candles_checksum' => 'sha256:' . str_repeat('b', 64),
                'quality_report_checksum' => 'sha256:' . str_repeat('c', 64),
                'source_checksum' => 'sha256:' . str_repeat('d', 64),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
            ],
            'symbol' => 'BTCUSDT',
            'requested_timeframes' => ['1m'],
            'candles_by_timeframe' => ['1m' => []],
        ], ['1m' => ['close' => 100.5]]);
    }

    private static function jsonFlags(): int
    {
        return JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    }
}
