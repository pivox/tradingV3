<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\MtfAuditEvent;
use App\Entity\MtfAudit;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class MtfAuditSubscriber implements EventSubscriberInterface
{
    private array $buffer = [];
    private int $batchSize = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly bool $skipFromCache = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MtfAuditEvent::NAME => 'onMtfAudit',
            KernelEvents::TERMINATE => 'onKernelTerminate',
            ConsoleEvents::TERMINATE => 'onConsoleTerminate',
        ];
    }

    public function onMtfAudit(MtfAuditEvent $event): void
    {
        try {
            $audit = $event->getAudit();
            // Si configuré, ignorer l'audit quand il provient du cache (details['from_cache'] === true)
            $details = $audit->getDetails();
            if ($this->skipFromCache && (bool)($details['from_cache'] ?? false) === true) {
                return;
            }
            $this->buffer[] = $audit;

            if (count($this->buffer) >= $this->batchSize) {
                $this->flushBuffer();
            }
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Audit] Failed to buffer audit', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->flushBuffer();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->flushBuffer();
    }

    private function flushBuffer(): void
    {
        if ($this->buffer === []) {
            return;
        }

        try {
            foreach ($this->buffer as $audit) {
                if ($audit instanceof MtfAudit) {
                    $this->entityManager->persist($audit);
                }
            }
            $this->entityManager->flush();

            // Détacher pour éviter l'accumulation en mémoire lors des longs processus
            foreach ($this->buffer as $audit) {
                if ($audit instanceof MtfAudit) {
                    try {
                        $this->entityManager->detach($audit);
                    } catch (\Throwable) {
                    }
                }
            }

            $this->buffer = [];
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Audit] Failed to flush audit buffer', [
                'error' => $e->getMessage(),
                'buffer_count' => count($this->buffer),
            ]);
        }
    }
}


