<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\EntryZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EntryZoneFilters
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $journeyLogger,
    ) {}

    /** @param array{request:mixed, preflight:mixed, plan:mixed, zone:EntryZone} $context */
    public function passAll(array $context): bool
    {
        $symbol = $context['request']->symbol ?? ($context['plan']->symbol ?? null);
        $decisionKey = $context['decision_key'] ?? null;
        $this->journeyLogger->debug('order_journey.entry_zone.filters_pass', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'reason' => 'filters_not_configured_default_pass',
        ]);

        return true; // TODO règles MTF (RSI<70, MA21+2×ATR, pullback confirmé, scaling progressif)
    }
}
