<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Exchange\Event\AbstractExchangePositionEvent;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;

/**
 * Projection order of the Fake exchange events of one Paper effect (#132).
 *
 * The Fake matching engine records a position change before the order fill that caused it.
 * The live projection resolves a position from its fill (CanonicalPositionRecoveryService),
 * so a position event that names a fill produced later in the same batch is moved right
 * after that fill. Nothing else moves: every other event keeps its recorded order.
 */
final class PaperFakeEventCausalOrder
{
    /**
     * @param list<ExchangeEventInterface> $events
     * @return list<ExchangeEventInterface>
     */
    public static function order(array $events): array
    {
        $fillPositions = [];
        foreach ($events as $index => $event) {
            if ($event instanceof ExchangeFillReceived && $event->fill()->fillId !== null) {
                $fillPositions[$event->fill()->fillId] ??= $index;
            }
        }
        /** @var array<int, list<ExchangeEventInterface>> $after events moved behind the fill at that index */
        $after = [];
        $ordered = [];
        foreach ($events as $index => $event) {
            $fillId = $event instanceof AbstractExchangePositionEvent ? $event->canonicalEvidence()->exchangeFillId : null;
            $fillAt = $fillId === null ? null : ($fillPositions[$fillId] ?? null);
            if ($fillAt !== null && $fillAt > $index) {
                $after[$fillAt][] = $event;
                continue;
            }
            $ordered[] = $event;
            foreach ($after[$index] ?? [] as $moved) {
                $ordered[] = $moved;
            }
        }

        return $ordered;
    }
}
