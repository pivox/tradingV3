<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

use App\TradingCore\Setup\Exception\SetupContractException;

final readonly class ConditionCatalog
{
    /** @var list<string> */
    public array $conditionIds;

    /** @param list<mixed> $conditionIds */
    public function __construct(array $conditionIds)
    {
        if (!array_is_list($conditionIds) || $conditionIds === []) {
            throw new SetupContractException('Condition catalog must be a non-empty list.');
        }
        $validated = [];
        foreach ($conditionIds as $conditionId) {
            if (!is_string($conditionId) || !in_array($conditionId, SetupContractValidator::CONDITION_IDS, true)) {
                throw new SetupContractException(sprintf('Unknown condition "%s".', is_scalar($conditionId) ? (string) $conditionId : get_debug_type($conditionId)));
            }
            $validated[] = $conditionId;
        }
        if (count(array_unique($validated)) !== count($validated)) {
            throw new SetupContractException('Condition catalog entries must be unique.');
        }
        sort($validated, SORT_STRING);
        $this->conditionIds = $validated;
    }

    public function stableHash(): string
    {
        return hash('sha256', json_encode($this->conditionIds, JSON_THROW_ON_ERROR));
    }
}
