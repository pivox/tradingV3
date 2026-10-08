<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SymfonyPaperCertificationCampaignRunningProcess implements PaperCertificationCampaignRunningProcessInterface
{
    /** Seconds a stopped child gets between SIGTERM and SIGKILL. */
    private const STOP_GRACE_SECONDS = 30;

    private ?PaperCertificationCampaignProcessResult $result = null;

    public function __construct(private readonly Process $process)
    {
    }

    public function poll(): ?PaperCertificationCampaignProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        try {
            // Also drains the pipes, so a verbose child never blocks on a full buffer.
            $this->process->checkTimeout();
        } catch (ProcessTimedOutException) {
            return $this->result = new PaperCertificationCampaignProcessResult(124, '', true);
        } catch (\Throwable $failure) {
            return $this->result = new PaperCertificationCampaignProcessResult(127, '', false, $failure->getMessage());
        }
        if ($this->process->isRunning()) {
            return null;
        }

        return $this->result = new PaperCertificationCampaignProcessResult(
            $this->process->getExitCode() ?? 1,
            $this->process->getOutput(),
            false,
            $this->process->getErrorOutput(),
        );
    }

    public function stop(): PaperCertificationCampaignProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        try {
            $exitCode = $this->process->stop(self::STOP_GRACE_SECONDS, \defined('SIGTERM') ? \SIGTERM : 15);
        } catch (\Throwable $failure) {
            return $this->result = new PaperCertificationCampaignProcessResult(137, '', false, $failure->getMessage());
        }

        return $this->result = new PaperCertificationCampaignProcessResult(
            $exitCode ?? 137,
            $this->process->getOutput(),
            false,
            $this->process->getErrorOutput(),
        );
    }
}
