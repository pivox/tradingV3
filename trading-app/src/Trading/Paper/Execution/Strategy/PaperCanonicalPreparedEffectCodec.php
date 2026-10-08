<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;

/**
 * A canonical prepared effect is journaled in PostgreSQL jsonb, which returns object keys
 * shorter-first (#132 decision h). The codec therefore never relies on key order: payload keys
 * are compared as sets, the lineage by its canonical JSON, and the order plan and admission
 * proof, whose TradingCore wire formats are order-strict, are kept as their exact JSON text (v2).
 */
final class PaperCanonicalPreparedEffectCodec
{
    private const SCHEMA_VERSION = 'paper-canonical-prepared-effect.v2';
    private const SERIALIZE_PRECISION_SETTING = 'serialize_precision';
    private const EXACT_SERIALIZE_PRECISION = '-1';
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
                'plan' => self::wireText($effect->plan->toArray()),
                'admission_proof' => self::wireText($effect->admissionProof->toArray()),
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
    public function supports(array $encoded): bool
    {
        return ($encoded['schema_version'] ?? null) === self::SCHEMA_VERSION;
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
                || !is_string($payload['plan'])
                || !is_string($payload['admission_proof'])
                || !is_array($payload['lineage'])
                || !is_array($payload['order_intent_identity'])
                || !is_array($payload['cell_provenance'])
                || !is_string($payload['decision_key'])
                || !is_string($payload['execution_timeframe'])
            ) {
                throw new \InvalidArgumentException();
            }

            $planWire = self::wireArray($payload['plan']);
            $proofWire = self::wireArray($payload['admission_proof']);
            $plan = CanonicalOrderPlan::fromArray($planWire);
            $proof = CanonicalPortfolioAdmissionProof::fromArray($proofWire);
            if ($plan->toArray() !== $planWire || $proof->toArray() !== $proofWire) {
                throw new \InvalidArgumentException();
            }
            $lineage = LineageContext::fromArray($payload['lineage']);
            if (CanonicalJson::encode($lineage->toArray()) !== CanonicalJson::encode($payload['lineage'])) {
                throw new \InvalidArgumentException();
            }
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
            $provenance = PaperExecutionProvenance::inCanonicalOrder($payload['cell_provenance']);

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
     * Exact JSON text of an order-strict wire array, so that jsonb keeps it byte for byte.
     *
     * @param array<string, mixed> $wire
     */
    private static function wireText(array $wire): string
    {
        $precision = ini_get(self::SERIALIZE_PRECISION_SETTING);
        if (!\is_string($precision) || ini_set(self::SERIALIZE_PRECISION_SETTING, self::EXACT_SERIALIZE_PRECISION) === false) {
            throw new \InvalidArgumentException();
        }
        try {
            return json_encode(
                $wire,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } finally {
            ini_set(self::SERIALIZE_PRECISION_SETTING, $precision);
        }
    }

    /** @return array<string, mixed> */
    private static function wireArray(string $text): array
    {
        $wire = json_decode($text, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!\is_array($wire) || array_is_list($wire) || self::wireText($wire) !== $text) {
            throw new \InvalidArgumentException();
        }

        return $wire;
    }

    /**
     * Exact key SET (#132 decision h): a pending effect is read back from PostgreSQL jsonb,
     * which returns object keys shorter-first, so the order is never part of the contract.
     *
     * @param array<array-key, mixed> $value
     * @param list<string> $expected
     */
    private static function assertKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException();
        }
    }
}
