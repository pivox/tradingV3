<?php

declare(strict_types=1);

namespace App\Command;

use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use App\TradingCore\Backtesting\Research\ResearchPlanSession;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:research:plans', description: 'Build research-only hypothetical plans from bound B1 signal artifacts.')]
final class ResearchPlanWorkerCommand extends Command
{
    public const MAX_LINE_BYTES = 2_097_152;

    public function __construct(
        private readonly ResearchPlanSession $session,
        private readonly StrictJsonObjectDecoder $decoder,
        private readonly ?\Closure $stdinOpener = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stream = null;
        $active = false;
        $closed = false;
        $sessionId = null;
        try {
            $stream = $this->stdinOpener !== null ? ($this->stdinOpener)() : fopen('php://stdin', 'rb');
            if (!is_resource($stream)) {
                throw new \InvalidArgumentException('research_plan_input_read_failed');
            }
            while (($line = fgets($stream, self::MAX_LINE_BYTES + 2)) !== false) {
                $payload = rtrim($line, "\r\n");
                if (strlen($payload) > self::MAX_LINE_BYTES
                    || (strlen($line) > self::MAX_LINE_BYTES && !str_ends_with($line, "\n"))) {
                    throw new \InvalidArgumentException('research_plan_input_too_large');
                }
                $frame = $this->decoder->decode($payload);
                switch ($frame['schema_version'] ?? null) {
                    case 'research-plan-open.v1':
                        if ($active) {
                            throw new \InvalidArgumentException('research_plan_session_already_open');
                        }
                        $opened = $this->session->open($frame);
                        $sessionId = $opened['session_id'];
                        $active = true;
                        $closed = false;
                        $this->emit($output, $opened);
                        break;
                    case 'research-plan-signal.v1':
                        if (!$active) {
                            throw new \InvalidArgumentException('research_plan_session_not_open');
                        }
                        $this->emit($output, $this->session->append($frame));
                        break;
                    case 'research-plan-close.v1':
                        if (!$active) {
                            throw new \InvalidArgumentException('research_plan_session_not_open');
                        }
                        $this->emit($output, $this->session->close($frame));
                        $active = false;
                        $closed = true;
                        break;
                    default:
                        throw new \InvalidArgumentException('research_plan_frame_schema_invalid');
                }
                if (defined('STDOUT') && is_resource(STDOUT)) {
                    fflush(STDOUT);
                }
            }
            if (!feof($stream)) {
                throw new \InvalidArgumentException('research_plan_input_read_failed');
            }
            if ($active || !$closed) {
                throw new \InvalidArgumentException('research_plan_explicit_close_required');
            }
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            if (preg_match('/\A[a-z0-9_]{1,100}\z/D', $reason) !== 1) {
                $reason = 'research_plan_worker_failed';
            }
            $this->emit($output, ['schema_version' => 'research-plan-error.v1',
                'session_id' => $sessionId, 'reason_code' => $reason]);
            return Command::INVALID;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param array<string, mixed> $frame */
    private function emit(OutputInterface $output, array $frame): void
    {
        $output->write(json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            false, OutputInterface::OUTPUT_RAW);
    }
}
