<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

final readonly class CanonicalMtfPolicyRejection
{
    /** @param list<array{code:string,path:string}> $blockers */
    public function __construct(
        public string $reason,
        public array $blockers,
    ) {
    }
}
