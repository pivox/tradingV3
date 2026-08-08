<?php

declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Config\TradeEntryConfig;
use App\Trading\Lineage\CanonicalRuntimePolicyException;

final class CanonicalTradeRuntimePolicyValidator
{
    /** @return list<array{code:string,path:string}> */
    public static function blockers(TradeEntryConfig $config): array
    {
        $blockers = [];
        if (!array_key_exists('risk_pct_percent', $config->getDefaults())) {
            $blockers[] = ['code' => 'canonical_risk_pct_pending_304', 'path' => 'runtime.trade_entry.risk_pct'];
        }
        $blockers[] = ['code' => 'canonical_daily_loss_policy_pending_304', 'path' => 'mode.risk.daily_loss_cap'];
        $blockers[] = ['code' => 'canonical_end_of_zone_fallback_pending_304', 'path' => 'runtime.trade_entry.fallback_end_of_zone'];
        $blockers[] = ['code' => 'canonical_max_concurrent_positions_pending_304', 'path' => 'mode.risk.max_concurrent_positions'];
        $blockers[] = ['code' => 'canonical_mode_exposure_cap_pending_304', 'path' => 'mode.risk.mode_exposure_cap'];
        $blockers[] = ['code' => 'canonical_minimum_net_r_pending_304', 'path' => 'setup.ast.execution.minimum_net_r'];

        return $blockers;
    }

    public static function assertReady(TradeEntryConfig $config): void
    {
        $blockers = self::blockers($config);
        if ($blockers !== []) {
            throw new CanonicalRuntimePolicyException($blockers);
        }
    }

    /** @param array<string,mixed>|null $decision */
    public static function assertNoEndOfZoneFallbackRewrite(bool $modern, ?array $decision): void
    {
        if ($modern && is_array($decision) && ($decision['order_type'] ?? null) === 'market') {
            throw new CanonicalRuntimePolicyException([
                ['code' => 'canonical_end_of_zone_fallback_pending_304', 'path' => 'runtime.trade_entry.fallback_end_of_zone'],
            ]);
        }
    }
}
