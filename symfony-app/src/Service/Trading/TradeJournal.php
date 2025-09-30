<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\TradeEvent;
use Doctrine\ORM\EntityManagerInterface;

final class TradeJournal
{
    public function __construct(private EntityManagerInterface $em) {}

    /** Méthode générique */
    public function log(
        string $aggregateType,
        string $aggregateId,
        string $type,
        ?array $payload = null,
        ?array $context = null,
        ?string $eventKey = null
    ): TradeEvent {
        // Idempotence simple: si eventKey renseignée et déjà présente, on ne duplique pas
        if ($eventKey) {
            $existing = $this->em->getRepository(TradeEvent::class)
                ->findOneBy(['eventKey' => $eventKey]);
            if ($existing) {
                return $existing;
            }
        }

        $e = new TradeEvent($aggregateType, $aggregateId, $type, $payload, $context, $eventKey);
        $this->em->persist($e);
        $this->em->flush();

        return $e;
    }

    // Helpers expressifs

    public function orderSubmitted(string $orderId, array $payload, ?array $ctx = null, ?string $key = null): TradeEvent
    {
        return $this->log('order', $orderId, 'OrderSubmitted', $payload, $ctx, $key);
    }

    public function orderAccepted(string $orderId, array $payload = [], ?array $ctx = null): TradeEvent
    {
        return $this->log('order', $orderId, 'OrderAccepted', $payload, $ctx);
    }

    public function orderFilled(string $orderId, array $payload = [], ?array $ctx = null): TradeEvent
    {
        return $this->log('order', $orderId, 'OrderFilled', $payload, $ctx);
    }

    public function orderRejected(string $orderId, array $payload = [], ?array $ctx = null): TradeEvent
    {
        return $this->log('order', $orderId, 'OrderRejected', $payload, $ctx);
    }

    public function positionOpened(string $positionId, array $payload = [], ?array $ctx = null): TradeEvent
    {
        return $this->log('position', $positionId, 'PositionOpened', $payload, $ctx);
    }

    public function positionClosed(string $positionId, array $payload = [], ?array $ctx = null): TradeEvent
    {
        return $this->log('position', $positionId, 'PositionClosed', $payload, $ctx);
    }
}
