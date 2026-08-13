<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backtest:rules:evaluate')]
final class BacktestEvaluateCanonicalRulesCommand extends Command
{
    private const MAX_INPUT_BYTES = 8_388_608;
    private const MAX_JSON_DEPTH = 128;

    public function __construct(
        private readonly CanonicalBacktestRuleEvaluatorInterface $evaluator,
        private readonly ?\Closure $stdinReader = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $payload = $this->readInput();
        } catch (\Throwable) {
            return $this->invalid($output, 'input_read_failed');
        }
        if (strlen($payload) > self::MAX_INPUT_BYTES) {
            return $this->invalid($output, 'input_too_large');
        }
        if (trim($payload) === '') {
            return $this->invalid($output, 'input_blank');
        }

        try {
            $this->assertNoDuplicateKeysAndBoundDepth($payload);
            $request = json_decode($payload, true, self::MAX_JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (DuplicateJsonKeyException) {
            return $this->invalid($output, 'duplicate_object_key');
        } catch (JsonDepthException) {
            return $this->invalid($output, 'json_depth_exceeded');
        } catch (\JsonException $exception) {
            return $this->invalid(
                $output,
                $exception->getCode() === JSON_ERROR_DEPTH ? 'json_depth_exceeded' : 'json_invalid',
            );
        }
        if (!\is_array($request) || ltrim($payload)[0] !== '{') {
            return $this->invalid($output, 'root_object_required');
        }

        try {
            $evaluation = $this->evaluator->evaluate($request);
            $encoded = json_encode(
                $evaluation->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            if (preg_match('/\Acanonical_[a-z0-9_:.\-]{1,160}\z/D', $reason) !== 1) {
                $reason = 'evaluation_failed';
            }

            return $this->invalid($output, $reason);
        }

        $output->write($encoded . "\n");

        return Command::SUCCESS;
    }

    private function readInput(): string
    {
        if ($this->stdinReader !== null) {
            return ($this->stdinReader)();
        }
        $stream = fopen('php://stdin', 'rb');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Unable to open stdin.');
        }
        try {
            $payload = stream_get_contents($stream, self::MAX_INPUT_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (!\is_string($payload)) {
            throw new \RuntimeException('Unable to read stdin.');
        }

        return $payload;
    }

    private function invalid(OutputInterface $output, string $reason): int
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error->writeln('canonical_backtest_rule_command_invalid:' . $reason);

        return Command::INVALID;
    }

    private function assertNoDuplicateKeysAndBoundDepth(string $json): void
    {
        /** @var list<array{type:'object'|'list',keys:array<string,true>,expecting_key:bool}> $stack */
        $stack = [];
        $length = strlen($json);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $json[$offset];
            if ($char === '"') {
                $start = $offset;
                for (++$offset; $offset < $length; ++$offset) {
                    if ($json[$offset] === '\\') {
                        ++$offset;
                        continue;
                    }
                    if ($json[$offset] === '"') {
                        break;
                    }
                }
                if ($offset >= $length) {
                    return;
                }
                $top = array_key_last($stack);
                if ($top === null || $stack[$top]['type'] !== 'object' || !$stack[$top]['expecting_key']) {
                    continue;
                }
                $lookahead = $offset + 1;
                while ($lookahead < $length && str_contains(" \t\r\n", $json[$lookahead])) {
                    ++$lookahead;
                }
                if ($lookahead >= $length || $json[$lookahead] !== ':') {
                    continue;
                }
                $key = json_decode(substr($json, $start, $offset - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                if (!\is_string($key)) {
                    return;
                }
                if (isset($stack[$top]['keys'][$key])) {
                    throw new DuplicateJsonKeyException();
                }
                $stack[$top]['keys'][$key] = true;
                $stack[$top]['expecting_key'] = false;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $stack[] = [
                    'type' => $char === '{' ? 'object' : 'list',
                    'keys' => [],
                    'expecting_key' => $char === '{',
                ];
                if (\count($stack) > self::MAX_JSON_DEPTH) {
                    throw new JsonDepthException();
                }
                continue;
            }
            if ($char === '}' || $char === ']') {
                array_pop($stack);
                continue;
            }
            if ($char === ',') {
                $top = array_key_last($stack);
                if ($top !== null && $stack[$top]['type'] === 'object') {
                    $stack[$top]['expecting_key'] = true;
                }
            }
        }
    }
}

final class DuplicateJsonKeyException extends \RuntimeException {}
final class JsonDepthException extends \RuntimeException {}
