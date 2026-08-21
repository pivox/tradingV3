<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;

final class PaperCanonicalPreparedEffectCodec
{
    private const SCHEMA_VERSION = 'paper-canonical-prepared-effect.v1';
    private const ENVELOPE_KEYS = ['schema_version', 'payload', 'payload_checksum'];
    private const PAYLOAD_KEYS = [
        'plan',
        'admission_proof',
        'lineage',
        'decision_key',
        'execution_timeframe',
        'order_intent_identity',
        'cell_provenance',
    ];

    /** @return array{schema_version: string, payload: array<string, mixed>, payload_checksum: string} */
    public function encode(PaperCanonicalPreparedEffect $effect): array
    {
        try {
            $effect->assertValid();
            $payload = [
                'plan' => $effect->plan->toArray(),
                'admission_proof' => $effect->admissionProof->toArray(),
                'lineage' => $effect->lineage->toArray(),
                'decision_key' => $effect->decisionKey,
                'execution_timeframe' => $effect->executionTimeframe,
                'order_intent_identity' => $effect->orderIntentIdentity,
                'cell_provenance' => $effect->provenance,
            ];

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'payload' => $payload,
                'payload_checksum' => hash('sha256', CanonicalJson::encode($payload)),
            ];
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('paper_canonical_prepared_effect_payload_invalid', 0, $exception);
        }
    }

    /** @param array<string, mixed> $encoded */
    public function decode(array $encoded): PaperCanonicalPreparedEffect
    {
        try {
            self::assertKeys($encoded, self::ENVELOPE_KEYS);
            if ($encoded['schema_version'] !== self::SCHEMA_VERSION
                || !is_array($encoded['payload'])
                || !is_string($encoded['payload_checksum'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $encoded['payload_checksum']) !== 1
            ) {
                throw new \InvalidArgumentException();
            }
            $payload = $encoded['payload'];
            self::assertKeys($payload, self::PAYLOAD_KEYS);
            if (!hash_equals(hash('sha256', CanonicalJson::encode($payload)), $encoded['payload_checksum'])
                || !is_array($payload['plan'])
                || !is_array($payload['admission_proof'])
                || !is_array($payload['lineage'])
                || !is_array($payload['order_intent_identity'])
                || !is_array($payload['cell_provenance'])
                || !is_string($payload['decision_key'])
                || !is_string($payload['execution_timeframe'])
            ) {
                throw new \InvalidArgumentException();
            }

            $plan = CanonicalOrderPlan::fromArray($payload['plan']);
            $proof = CanonicalPortfolioAdmissionProof::fromArray($payload['admission_proof']);
            $lineage = LineageContext::fromArray($payload['lineage']);
            $snapshot = $lineage->effectiveConfigSnapshot;
            if ($snapshot === null) {
                throw new \InvalidArgumentException();
            }
            $reservation = $proof->openReservation(
                $plan,
                CanonicalPortfolioPolicy::fromLineageSnapshot($snapshot),
            );

            /** @var array{client_order_id: string, order_intent_id: int} $orderIntentIdentity */
            $orderIntentIdentity = $payload['order_intent_identity'];
            /** @var array<string, string> $provenance */
            $provenance = $payload['cell_provenance'];

            return new PaperCanonicalPreparedEffect(
                $plan,
                $proof,
                $reservation,
                $lineage,
                $payload['decision_key'],
                $payload['execution_timeframe'],
                $orderIntentIdentity,
                $provenance,
            );
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('paper_canonical_prepared_effect_payload_invalid', 0, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $expected
     */
    private static function assertKeys(array $value, array $expected): void
    {
        if (array_keys($value) !== $expected) {
            throw new \InvalidArgumentException();
        }
    }
}
