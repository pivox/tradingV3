<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;

final readonly class PaperCanonicalStrategyPreparation implements PaperCanonicalStrategyPreparationInterface
{
    public function __construct(
        private PaperCanonicalStrategyInputAssemblerInterface $assembler,
        private PaperCanonicalStrategyRuntimeInterface $runtime,
    ) {
    }

    public function prepareFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        string $sourceDatasetId,
        string $sourceEventsFileSha256,
        string $sourceBuildVersion,
    ): ?PaperCanonicalStrategyDecision {
        $input = $this->assembler->assemble(
            $cell,
            $event,
            $sourceDatasetId,
            $sourceEventsFileSha256,
            $sourceBuildVersion,
        );
        if ($input === null) {
            return null;
        }

        $outcome = $this->runtime->run($input->request, $this->policy($cell));
        if ($outcome->status === 'no_trade') {
            return null;
        }

        try {
            $plan = $outcome->orderPlan
                ?? throw new \LogicException('paper_canonical_strategy_outcome_invalid');
            $reservation = $outcome->reservation
                ?? throw new \LogicException('paper_canonical_strategy_outcome_invalid');
            $proofData = $outcome->evidence['admission_proof'] ?? null;
            if (!is_array($proofData) || array_is_list($proofData)) {
                throw new \LogicException('paper_canonical_strategy_outcome_invalid');
            }
            $proof = CanonicalPortfolioAdmissionProof::fromArray($proofData);
            $snapshot = $outcome->lineage->effectiveConfigSnapshot;
            if ($snapshot === null
                || $outcome->lineage != $input->request->lineage
                || $outcome->lineage->decisionKey !== $input->request->decisionKey
                || $reservation->decisionKey !== $input->request->decisionKey
            ) {
                throw new \LogicException('paper_canonical_strategy_outcome_invalid');
            }
            $policy = CanonicalPortfolioPolicy::fromLineageSnapshot($snapshot);
            $proof->verify($plan, $reservation, $policy);
            $proof = CanonicalPortfolioAdmissionProof::fromReservation(
                new CanonicalPortfolioAdmissionRequest(
                    $policy,
                    $plan,
                    $input->request->portfolioScope,
                    $input->request->portfolioSnapshot,
                    $input->request->decisionKey,
                ),
                $reservation,
            );

            return new PaperCanonicalStrategyDecision(
                $plan,
                $proof,
                $reservation,
                $outcome->lineage,
                $input->request->decisionKey,
                $input->executionTimeframe,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof \LogicException
                && $exception->getMessage() === 'paper_canonical_strategy_outcome_invalid'
            ) {
                throw $exception;
            }

            throw new \LogicException('paper_canonical_strategy_outcome_invalid', 0, $exception);
        }
    }

    private function policy(PaperExecutionCell $cell): ShadowRuntimeIdentityPolicy
    {
        $identity = $cell->modernIdentity
            ?? throw new \LogicException('paper_canonical_strategy_cell_identity_missing');

        return new ShadowRuntimeIdentityPolicy(
            'paper_canonical_strategy',
            [[
                'mode_id' => $identity->modeId,
                'mode_version' => $identity->modeVersion,
                'setup_id' => $identity->setupId,
                'setup_version' => $identity->setupVersion,
                'side' => $identity->side,
            ]],
            requiresCanonicalOrderBook: in_array(
                $identity->modeId,
                ['scalping', 'micro_scalping'],
                true,
            ),
            requiresCanonicalMicrostructure: $identity->modeId === 'micro_scalping',
        );
    }
}
