<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogException;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Catalog\ConditionCatalogResolver;
use App\TradingCore\Rules\Compiler\RuleCompilationException;
use App\TradingCore\Rules\Compiler\StrictSetupRuleCompiler;
use App\TradingCore\Setup\Exception\SetupContractException;
use App\TradingCore\Setup\SetupContractLoader;

final readonly class CanonicalRulePolicyPreflight
{
    private ?ConditionCatalog $catalog;
    private SetupContractLoader $setupContracts;
    private ?StrictSetupRuleCompiler $compiler;

    public function __construct(
        ?ConditionCatalog $catalog = null,
        ?SetupContractLoader $setupContracts = null,
        ?StrictSetupRuleCompiler $compiler = null,
    ) {
        $this->catalog = $catalog;
        $this->setupContracts = $setupContracts ?? new SetupContractLoader();
        $this->compiler = $compiler;
    }

    public static function catalogHash(): string
    {
        return self::loadCatalog()->stableHash();
    }

    /** @return list<array{code:string,path:string}> */
    public function blockers(LineageContext $identity): array
    {
        if (!$identity->isModern()) {
            return [];
        }
        $identityHash = $identity->conditionCatalogHash;
        $identityHash = is_string($identityHash) && str_starts_with($identityHash, 'sha256:')
            ? substr($identityHash, 7)
            : $identityHash;
        try {
            $contract = $this->setupContracts->load((string) $identity->setupId, (string) $identity->setupVersion);
            $catalog = (new ConditionCatalogResolver())->forSetupDocument($contract->toArray(), $this->catalog);
            if (!is_string($identityHash) || !hash_equals($catalog->stableHash(), $identityHash)) {
                return [['code' => 'canonical_condition_catalog_mismatch', 'path' => 'condition_catalog_hash']];
            }
            if (strtoupper($contract->side) !== $identity->side) {
                return [['code' => 'canonical_setup_side_mismatch', 'path' => 'setup.side']];
            }
            $plan = ($this->compiler ?? new StrictSetupRuleCompiler($catalog))->compile($contract);
        } catch (ConditionCatalogException|SetupContractException|RuleCompilationException $exception) {
            return [[
                'code' => 'canonical_rule_compilation_failed',
                'path' => 'setup.ast',
            ]];
        }
        $blockers = [];
        foreach ($plan->blockers as $blocker) {
            if (str_starts_with($blocker, 'blocked_condition:')) {
                $condition = substr($blocker, strlen('blocked_condition:'));
                $blockers[] = ['code' => 'canonical_condition_blocked:' . $condition, 'path' => 'condition_catalog.' . $condition];
            }
        }
        if (!$contract->isExecutable()) {
            $blockers[] = ['code' => 'canonical_setup_contract_not_executable', 'path' => 'setup.status'];
        }

        return $blockers;
    }

    private static function loadCatalog(): ConditionCatalog
    {
        return (new ConditionCatalogLoader())->loadVersion('1.0.0');
    }
}
