<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Compiler;

use App\TradingCore\Rules\Ast\RuleNode;

final readonly class CompiledSetupRulePlan
{
    /**
     * @param array<string, RuleNode> $sections
     * @param list<RuleNode> $filters
     * @param list<RuleNode> $noTradeRules
     * @param list<string> $blockers
     */
    public function __construct(
        public string $schemaVersion,
        public string $setupId,
        public string $setupVersion,
        public string $side,
        public string $catalogVersion,
        public string $catalogHash,
        public array $sections,
        public array $filters,
        public array $noTradeRules,
        public array $blockers,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $sections = [];
        foreach ($this->sections as $name => $node) {
            $sections[$name] = $node->toArray();
        }

        return [
            'schema_version' => $this->schemaVersion,
            'setup_id' => $this->setupId,
            'setup_version' => $this->setupVersion,
            'side' => $this->side,
            'catalog_version' => $this->catalogVersion,
            'catalog_hash' => $this->catalogHash,
            'sections' => $sections,
            'filters' => array_map(static fn (RuleNode $node): array => $node->toArray(), $this->filters),
            'no_trade_rules' => array_map(static fn (RuleNode $node): array => $node->toArray(), $this->noTradeRules),
            'blockers' => $this->blockers,
        ];
    }
}
