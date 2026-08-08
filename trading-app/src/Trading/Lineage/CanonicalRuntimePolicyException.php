<?php

declare(strict_types=1);

namespace App\Trading\Lineage;

final class CanonicalRuntimePolicyException extends \RuntimeException
{
    /** @param list<array{code:string,path:string}> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct($blockers[0]['code'] ?? 'canonical_runtime_policy_pending_304');
    }
}
