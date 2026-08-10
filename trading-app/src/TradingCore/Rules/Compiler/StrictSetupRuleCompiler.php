<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Compiler;

use App\TradingCore\Rules\Ast\AllOfNode;
use App\TradingCore\Rules\Ast\AnyOfNode;
use App\TradingCore\Rules\Ast\ConditionNode;
use App\TradingCore\Rules\Ast\RuleNode;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Setup\SetupContract;

final readonly class StrictSetupRuleCompiler
{
    private RuleExpressionCompiler $expressionCompiler;

    public function __construct(private ConditionCatalog $catalog)
    {
        $this->expressionCompiler = new RuleExpressionCompiler($catalog);
    }

    public function compile(SetupContract $contract): CompiledSetupRulePlan
    {
        $document = $contract->toArray();
        $declaredHash = $document['data_condition_contract']['condition_catalog_hash'];
        if (($declaredHash['state'] ?? null) === 'defined'
            && (!is_string($declaredHash['value'] ?? null) || !hash_equals($this->catalog->stableHash(), $declaredHash['value']))) {
            throw new RuleCompilationException('Condition catalog hash mismatch; compilation fails closed.');
        }
        $sections = [];
        foreach (['regime', 'context', 'trigger', 'confirmations'] as $section) {
            $expression = $document['context'][$section] ?? null;
            if (!is_array($expression) || array_is_list($expression)) {
                throw new RuleCompilationException(sprintf('context.%s must be an expression mapping.', $section));
            }
            $sections[$section] = $this->expressionCompiler->compile($expression, $contract->side);
        }
        $filters = $this->compileList($document['filters'] ?? null, $contract->side, 'filters');
        $noTradeRules = $this->compileList($document['no_trade_rules'] ?? null, $contract->side, 'no_trade_rules');
        $blockers = [];
        foreach (array_merge(array_values($sections), $filters, $noTradeRules) as $node) {
            $this->collectBlockedConditions($node, $blockers);
        }
        if (!$contract->isExecutable()) {
            $blockers[] = 'contract_not_executable:' . $contract->status;
        }
        if (($declaredHash['state'] ?? null) !== 'defined') {
            $blockers[] = 'condition_catalog_hash_unresolved';
        }
        foreach ($contract->unresolvedPaths() as $path) {
            $blockers[] = 'unresolved:' . $path;
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return new CompiledSetupRulePlan(
            'compiled-rule-plan.v1',
            $contract->setupId,
            $contract->setupVersion,
            $contract->stableHash(),
            $contract->side,
            $this->catalog->catalogVersion,
            $this->catalog->stableHash(),
            $sections,
            $filters,
            $noTradeRules,
            $blockers,
        );
    }

    /** @return list<RuleNode> */
    private function compileList(mixed $value, string $side, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuleCompilationException($path . ' must be a list.');
        }
        $nodes = [];
        foreach ($value as $index => $expression) {
            if (!is_array($expression) || array_is_list($expression)) {
                throw new RuleCompilationException(sprintf('%s[%d] must be an expression mapping.', $path, $index));
            }
            $nodes[] = $this->expressionCompiler->compile($expression, $side);
        }

        return $nodes;
    }

    /** @param list<string> $blockers */
    private function collectBlockedConditions(RuleNode $node, array &$blockers): void
    {
        if ($node instanceof ConditionNode) {
            if ($this->catalog->definition($node->conditionId)->status === 'blocked') {
                $blockers[] = 'blocked_condition:' . $node->conditionId;
            }
            return;
        }
        if ($node instanceof AllOfNode || $node instanceof AnyOfNode) {
            foreach ($node->children as $child) {
                $this->collectBlockedConditions($child, $blockers);
            }
        }
    }
}
