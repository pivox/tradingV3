<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

use App\TradingCore\Config\EffectiveTradingConfigSnapshot;

final readonly class EffectiveConfigViewerDocumentFactory
{
    public const RESOLVER_VERSION = '1.0.0';

    public function __construct(private EffectiveConfigRedactor $redactor)
    {
    }

    public function fromSnapshot(EffectiveTradingConfigSnapshot $snapshot): EffectiveConfigViewerDocument
    {
        $result = $this->redactor->redact($snapshot->toArray());

        return new EffectiveConfigViewerDocument([
            'document_kind' => 'current_preview',
            'resolver_version' => self::RESOLVER_VERSION,
            'validation_status' => 'valid',
            'redacted_paths' => $result->redactedPaths,
            ...$result->document,
        ]);
    }
}
