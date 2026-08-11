<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use Brick\Math\BigDecimal;
use Symfony\Component\Clock\MockClock;

final readonly class CanonicalPortfolioAdmissionProof
{
    private const SCHEMA = 'canonical-portfolio-admission-proof.v1';

    private function __construct(
        public string $decisionKey,
        public CanonicalPortfolioPolicy $policy,
        public CanonicalPortfolioScope $scope,
        public CanonicalPortfolioSnapshot $snapshot,
    ) {
    }

    public static function fromRequest(CanonicalPortfolioAdmissionRequest $request): self
    {
        return new self(
            $request->decisionKey,
            $request->policy,
            $request->scope,
            $request->snapshot,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $activeDecisionKeys = $this->snapshot->activeDecisionKeys;
        sort($activeDecisionKeys, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'decision_key' => $this->decisionKey,
            'policy' => $this->policy->toAdmissionProofArray(),
            'scope' => $this->scope->toArray(),
            'snapshot' => [
                'source' => $this->snapshot->source,
                'source_version' => $this->snapshot->sourceVersion,
                'policy_day_start' => self::time($this->snapshot->policyDayStart),
                'policy_day_end' => self::time($this->snapshot->policyDayEnd),
                'observed_at' => self::time($this->snapshot->observedAt),
                'equity_quote' => self::decimal($this->snapshot->equityQuote),
                'realized_net_pnl_quote' => self::decimal($this->snapshot->realizedNetPnlQuote),
                'unrealized_net_pnl_quote' => self::decimal($this->snapshot->unrealizedNetPnlQuote),
                'open_positions' => $this->snapshot->openPositions,
                'pending_entries' => $this->snapshot->pendingEntries,
                'open_notional_quote' => self::decimal($this->snapshot->openNotionalQuote),
                'pending_notional_quote' => self::decimal($this->snapshot->pendingNotionalQuote),
                'reserved_risk_quote' => self::decimal($this->snapshot->reservedRiskQuote),
                'active_decision_keys' => $activeDecisionKeys,
                'state_version' => $this->snapshot->stateVersion,
                'input_hash' => $this->snapshot->inputHash,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            self::exactKeys($data, ['schema', 'decision_key', 'policy', 'scope', 'snapshot']);
            if (($data['schema'] ?? null) !== self::SCHEMA || !\is_string($data['decision_key'] ?? null)) {
                throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
            }
            $policyData = self::mapping($data, 'policy');
            $scopeData = self::mapping($data, 'scope');
            self::exactKeys($scopeData, ['network', 'exchange', 'environment', 'account_id', 'mode_id', 'quote_currency']);
            foreach ($scopeData as $value) {
                if (!\is_string($value)) {
                    throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
                }
            }
            $scope = new CanonicalPortfolioScope(
                $scopeData['network'],
                $scopeData['exchange'],
                $scopeData['environment'],
                $scopeData['account_id'],
                $scopeData['mode_id'],
                $scopeData['quote_currency'],
            );
            $snapshotData = self::mapping($data, 'snapshot');
            self::exactKeys($snapshotData, [
                'source',
                'source_version',
                'policy_day_start',
                'policy_day_end',
                'observed_at',
                'equity_quote',
                'realized_net_pnl_quote',
                'unrealized_net_pnl_quote',
                'open_positions',
                'pending_entries',
                'open_notional_quote',
                'pending_notional_quote',
                'reserved_risk_quote',
                'active_decision_keys',
                'state_version',
                'input_hash',
            ]);
            foreach (['source', 'source_version', 'policy_day_start', 'policy_day_end', 'observed_at', 'input_hash'] as $field) {
                if (!\is_string($snapshotData[$field])) {
                    throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
                }
            }
            foreach (['open_positions', 'pending_entries', 'state_version'] as $field) {
                if (!\is_int($snapshotData[$field])) {
                    throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
                }
            }
            if (!\is_array($snapshotData['active_decision_keys']) || !array_is_list($snapshotData['active_decision_keys'])) {
                throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
            }
            $activeDecisionKeys = $snapshotData['active_decision_keys'];
            foreach ($activeDecisionKeys as $decisionKey) {
                if (!\is_string($decisionKey)) {
                    throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
                }
            }
            $sortedDecisionKeys = $activeDecisionKeys;
            sort($sortedDecisionKeys, SORT_STRING);
            if ($activeDecisionKeys !== $sortedDecisionKeys) {
                throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
            }
            $snapshot = new CanonicalPortfolioSnapshot(
                $scope,
                $snapshotData['source'],
                $snapshotData['source_version'],
                self::date($snapshotData['policy_day_start']),
                self::date($snapshotData['policy_day_end']),
                self::date($snapshotData['observed_at']),
                self::float($snapshotData['equity_quote']),
                self::float($snapshotData['realized_net_pnl_quote']),
                self::float($snapshotData['unrealized_net_pnl_quote']),
                $snapshotData['open_positions'],
                $snapshotData['pending_entries'],
                self::float($snapshotData['open_notional_quote']),
                self::float($snapshotData['pending_notional_quote']),
                self::float($snapshotData['reserved_risk_quote']),
                $activeDecisionKeys,
                $snapshotData['state_version'],
                $snapshotData['input_hash'],
            );
            $proof = new self(
                $data['decision_key'],
                CanonicalPortfolioPolicy::fromAdmissionProofArray($policyData),
                $scope,
                $snapshot,
            );
            if ($proof->toArray() !== $data) {
                throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
            }

            return $proof;
        } catch (CanonicalPortfolioException $exception) {
            if ($exception->reasonCode === 'canonical_portfolio_admission_proof_invalid') {
                throw $exception;
            }

            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid', [], $exception);
        } catch (\Throwable $exception) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid', [], $exception);
        }
    }

    public function verify(CanonicalOrderPlan $plan, CanonicalPortfolioReservation $reservation): self
    {
        try {
            $request = new CanonicalPortfolioAdmissionRequest(
                $this->policy,
                $plan,
                $this->scope,
                $this->snapshot,
                $this->decisionKey,
            );
            $decision = (new CanonicalPortfolioAdmissionEngine(new MockClock($reservation->observedAt)))
                ->admit($request);
            $expected = CanonicalPortfolioReservation::open($decision, $plan);
            $expected->assertCanonicalOpeningState($plan);
        } catch (\Throwable $exception) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_verification_failed', [], $exception);
        }
        if ($expected != $reservation) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_verification_failed');
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function mapping(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $expected
     */
    private static function exactKeys(array $data, array $expected): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
        }
    }

    private static function time(\DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.uP');
    }

    private static function date(string $value): \DateTimeImmutable
    {
        $date = new \DateTimeImmutable($value);
        if (self::time($date) !== $value) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
        }

        return $date;
    }

    private static function decimal(float $value): string
    {
        return CanonicalPortfolioDecimal::fromFloat($value, 'canonical_portfolio_admission_proof_invalid')
            ->stripTrailingZeros()
            ->__toString();
    }

    private static function float(mixed $value): float
    {
        if (!\is_string($value) || preg_match('/\A-?(0|[1-9]\d*)(?:\.\d*[1-9])?\z/D', $value) !== 1) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
        }

        return CanonicalPortfolioDecimal::toFiniteFloat(
            BigDecimal::of($value),
            'canonical_portfolio_admission_proof_invalid',
        );
    }
}
