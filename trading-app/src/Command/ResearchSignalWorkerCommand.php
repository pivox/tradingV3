<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use App\TradingCore\Backtesting\Research\ResearchSignalSession;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:research:signals', description: 'Stream canonical baseline signals from explicitly bound historical research candles.')]
final class ResearchSignalWorkerCommand extends Command
{
    public const MAX_LINE_BYTES = 2097152;

    public function __construct(
        private readonly ResearchSignalSession $session,
        private readonly StrictJsonObjectDecoder $decoder,
        private readonly ?\Closure $stdinOpener = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stream = null; $active = false; $closed = false; $sessionId = null;
        try {
            $stream = $this->stdinOpener !== null ? ($this->stdinOpener)() : fopen('php://stdin', 'rb');
            if (!is_resource($stream)) { throw new \InvalidArgumentException('research_input_read_failed'); }
            while (($line = fgets($stream, self::MAX_LINE_BYTES + 2)) !== false) {
                $payload = rtrim($line, "\r\n");
                if (strlen($payload) > self::MAX_LINE_BYTES || (strlen($line) > self::MAX_LINE_BYTES && !str_ends_with($line, "\n"))) { throw new \InvalidArgumentException('research_input_too_large'); }
                $frame = $this->decoder->decode($payload);
                switch ($frame['schema_version'] ?? null) {
                    case 'research-signal-open.v1':
                        if ($active) { throw new \InvalidArgumentException('research_session_already_open'); }
                        $opened = $this->session->open($frame);
                        $sessionId = $opened['session_id']; $active = true; $closed = false;
                        $this->emit($output, $opened);
                        break;
                    case 'research-signal-candles.v1':
                        $results = $this->session->appendBatch($frame);
                        foreach ($results as $result) { $this->emit($output, $result); }
                        $this->emit($output, ['schema_version' => 'research-signal-batch-accepted.v1', 'session_id' => $sessionId, 'consumed_candles' => count($frame['candles']), 'emitted_results' => count($results)]);
                        break;
                    case 'research-signal-close.v1':
                        $this->emit($output, $this->session->close($frame));
                        $active = false; $closed = true;
                        break;
                    default:
                        throw new \InvalidArgumentException('research_frame_schema_invalid');
                }
                if (defined('STDOUT') && is_resource(STDOUT)) { fflush(STDOUT); }
            }
            if (!feof($stream)) { throw new \InvalidArgumentException('research_input_read_failed'); }
            if ($active || !$closed) { throw new \InvalidArgumentException('research_explicit_close_required'); }
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            if (preg_match('/\A[a-z0-9_]{1,100}\z/D', $reason) !== 1) { $reason = 'research_worker_failed'; }
            $this->emit($output, ['schema_version' => 'research-signal-error.v1', 'session_id' => $sessionId, 'reason_code' => $reason]);
            return Command::INVALID;
        } finally {
            if (is_resource($stream)) { fclose($stream); }
        }
    }

    /** @param array<string, mixed> $frame */
    private function emit(OutputInterface $output, array $frame): void
    {
        $output->write(json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", false, OutputInterface::OUTPUT_RAW);
    }
}
