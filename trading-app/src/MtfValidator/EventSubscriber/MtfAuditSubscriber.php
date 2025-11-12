<?php

declare(strict_types=1);

namespace App\MtfValidator\EventSubscriber;

use App\MtfValidator\Event\MtfAuditEvent;
use App\MtfValidator\Entity\MtfAudit;
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
            // Créer l'entité MtfAudit à partir des données de l'événement
            $audit = new MtfAudit();
            $audit->setSymbol($event->getSymbol());
            $audit->setStep($event->getStep());
            $audit->setCause($event->getMessage());
            $audit->setDetails($event->getData());
            $audit->setSeverity($event->getSeverity());
            
            // Définir le run_id depuis les données si disponible
            $data = $event->getData();
            if (isset($data['run_id']) && $data['run_id'] !== null) {
                try {
                    $runId = \Ramsey\Uuid\Uuid::fromString($data['run_id']);
                    $audit->setRunId($runId);
                } catch (\Throwable $e) {
                    // Si le run_id n'est pas un UUID valide, on l'ignore
                    $this->logger->warning('[MTF Audit] Invalid run_id format', [
                        'run_id' => $data['run_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
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
            $em = $this->entityManager;
            $isOpen = true;
            try {
                if (method_exists($em, 'isOpen')) {
                    $isOpen = (bool) $em->isOpen();
                }
            } catch (\Throwable) {
                $isOpen = true;
            }

            if (!$isOpen) {
                $this->logger->warning('[MTF Audit] EntityManager closed; dropping audit buffer (best-effort)', [
                    'buffer_count' => count($this->buffer),
                ]);
                $this->buffer = [];
                return;
            }

            foreach ($this->buffer as $audit) {
                if ($audit instanceof MtfAudit) {
                    try {
                        $em->persist($audit);
                    } catch (\Throwable $persistEx) {
                        $this->logger->warning('[MTF Audit] Failed to persist audit (skipping)', [
                            'error' => $persistEx->getMessage(),
                        ]);
                    }
                }
            }
            try {
                $em->flush();
            } catch (\Throwable $flushEx) {
                $this->logger->warning('[MTF Audit] Failed to flush audits (best-effort)', [
                    'error' => $flushEx->getMessage(),
                ]);
                // Drop buffer to avoid memory growth on long processes
                $this->buffer = [];
                return;
            }

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

