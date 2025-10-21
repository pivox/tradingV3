<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\MtfAudit;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement émis à chaque appel d'auditStep pour déléguer la persistance via un listener.
 */
class MtfAuditEvent extends Event
{
    public const NAME = 'mtf.audit.step';

    public function __construct(private readonly MtfAudit $audit)
    {
    }

    public function getAudit(): MtfAudit
    {
        return $this->audit;
    }
}


