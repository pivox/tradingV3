<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

use App\TradeEntry\Policy\CanonicalTradeRuntimePolicyValidator;
use App\Trading\Lineage\CanonicalRuntimePolicyException;
use App\Trading\Lineage\CanonicalTradeEntryConfigFactory;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;

final class CanonicalMtfPolicyPreflight
{
    public function reject(LineageContext $identity): ?CanonicalMtfPolicyRejection
    {
        if (!$identity->isModern()) {
            return null;
        }

        try {
            CanonicalTradeRuntimePolicyValidator::assertReady(
                CanonicalTradeEntryConfigFactory::fromLineage($identity),
            );
            $blockers = [[
                'code' => 'canonical_mtf_evaluator_pending_303',
                'path' => 'runtime.mtf.condition_evaluator',
            ]];
        } catch (CanonicalRuntimePolicyException $exception) {
            $blockers = $exception->blockers;
        } catch (LineageContextException $exception) {
            $blockers = [[
                'code' => $exception->getMessage(),
                'path' => 'effective_config_snapshot',
            ]];
        }

        return new CanonicalMtfPolicyRejection($blockers[0]['code'], $blockers);
    }
}
