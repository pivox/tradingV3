<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final readonly class EffectiveConfigViewerDocument
{
    /** @param array<string,mixed> $payload */
    public function __construct(public array $payload)
    {
    }

    public function snapshotHash(): string
    {
        return $this->requiredString('snapshot_hash');
    }

    public function configHash(): string
    {
        return $this->requiredString('config_hash');
    }

    public function canonicalJson(): string
    {
        return EffectiveConfigCanonicalJson::encode($this->payload);
    }

    public function redactedContentChecksum(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    /** @return array<string,mixed> */
    public function withDocumentKind(string $kind): array
    {
        return ['document_kind' => $kind] + array_diff_key($this->payload, ['document_kind' => true]);
    }

    private function requiredString(string $key): string
    {
        $value = $this->payload[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \LogicException('effective_config_document_invalid');
        }

        return $value;
    }
}
