<?php

declare(strict_types=1);

namespace App\MtfValidator\MessageHandler;

use App\MtfValidator\Message\MtfResultProjectionMessage;
use App\MtfValidator\Service\MtfResultProjector;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MtfResultProjectionMessageHandler
{
    public function __construct(
        private readonly MtfResultProjector $projector,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $mtfLogger,
    ) {
    }

    public function __invoke(MtfResultProjectionMessage $message): void
    {
        try {
            $this->projector->project($message->runId, $message->mtfRun, $message->result);

            if (!method_exists($this->em, 'isOpen') || $this->em->isOpen()) {
                $this->em->flush();
            }
        } catch (\Throwable $exception) {
            $this->mtfLogger->error('[MTF Projection] Failed to persist result', [
                'run_id' => $message->runId,
                'symbol' => $message->result->symbol ?? null,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

