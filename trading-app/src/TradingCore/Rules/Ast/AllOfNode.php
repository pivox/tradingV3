<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Ast;

final readonly class AllOfNode implements RuleNode
{
    /** @param non-empty-list<RuleNode> $children */
    public function __construct(public array $children, public string $provenance)
    {
    }

    public function toArray(): array
    {
        return [
            'kind' => 'all_of',
            'children' => array_map(static fn (RuleNode $node): array => $node->toArray(), $this->children),
            'provenance' => $this->provenance,
        ];
    }
}
