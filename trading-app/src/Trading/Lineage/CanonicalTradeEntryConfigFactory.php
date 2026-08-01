<?php

declare(strict_types=1);

namespace App\Trading\Lineage;

use App\Config\TradeEntryConfig;

final class CanonicalTradeEntryConfigFactory
{
    public static function fromLineage(LineageContext $identity): TradeEntryConfig
    {
        $identity->assertExecutableTradeContract();
        $effective = $identity->effectiveConfigSnapshot?->config() ?? [];
        $view = $effective['trade_entry'] ?? null;
        if (!\is_array($view)) {
            throw new LineageContextException('canonical_identity_missing:trade_entry_config_view');
        }
        foreach (['defaults', 'entry', 'risk', 'leverage', 'decision', 'fees'] as $section) {
            if (!\is_array($view[$section] ?? null)) {
                throw new LineageContextException('canonical_identity_missing:trade_entry_config_view.' . $section);
            }
        }
        return new TradeEntryConfig(config: $view + ['version' => $identity->setupVersion]);
    }
}
