<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Ast;

final readonly class AnyOfNode implements RuleNode
{
    /** @param list<RuleNode> $children */
    public function __construct(public array $children, public string $provenance)
    {
        if ($children === []) {
            throw new \InvalidArgumentException('any_of children must not be empty.');
        }
    }

    public function toArray(): array
    {
        return [
            'kind' => 'any_of',
            'children' => array_map(static fn (RuleNode $node): array => $node->toArray(), $this->children),
            'provenance' => $this->provenance,
        ];
    }
}
