<?php

namespace App\Indicator\ConditionLoader\Cards\Validation;

use App\Indicator\ConditionLoader\Cards\AbstractCard;
use App\Indicator\ConditionLoader\ConditionRegistry;

class ListSideElements extends AbstractCard
{
    public const MODE_ALL = 'all';
    public const MODE_ANY = 'any';

    /** @var SideElementInterface[] */
    private array $sideElements = [];

    public function __construct(
        private readonly ConditionRegistry $conditionRegistry
    ) {}

    /**
     * @throws \Exception
     */
    public function fill(string|array $data): static
    {
        $items = is_array($data) ? $data : [$data];
        $this->sideElements = [];

        foreach ($items as $element) {
            if (is_string($element)) {
                $this->sideElements[] = (new SideElementSimple($this->conditionRegistry))->fill($element);
                continue;
            }

            if (!is_array($element) || $element === []) {
                continue;
            }

            $firstKey = array_key_first($element);

            if (is_string($firstKey) && in_array($firstKey, [
                SideElementConditional::CONDITION_ANY_OF,
                SideElementConditional::CONDITION_ALL_OF
            ], true)) {
                $this->sideElements[] = (new SideElementConditional())->fill($element);
                continue;
            }

            if (is_int($firstKey)) {
                $this->sideElements[] = (new SideElementConditional())->fill([
                    SideElementConditional::CONDITION_ALL_OF => $element
                ]);
                continue;
            }

            if (is_string($firstKey)) {
                $this->sideElements[] = (new SideElementSimple($this->conditionRegistry))->fill($element);
                continue;
            }

            throw new \Exception('Unsupported validation element structure');
        }
        return $this;
    }

    public function evaluate(array $payload, string $mode = self::MODE_ALL): array
    {
        $results = [];
        $statuses = [];

        foreach ($this->sideElements as $condition) {
            $results[] = $condition->evaluate($payload);
            $statuses[] = $condition->isValid();
        }

        $statusesEmpty = count($statuses) === 0;

        if ($mode === self::MODE_ANY) {
            $this->isValid = !$statusesEmpty && in_array(true, $statuses, true);
        } elseif ($mode === self::MODE_ALL) {
            $this->isValid = !$statusesEmpty ? !in_array(false, $statuses, true) : true;
        } else {
            throw new \InvalidArgumentException(sprintf('Unknown evaluation mode "%s"', $mode));
        }

        return [
            'mode' => $mode,
            'passed' => $this->isValid,
            'items' => $results,
        ];
    }

    /**
     * @return SideElementInterface[]
     */
    public function getSideElements(): array
    {
        return $this->sideElements;
    }
}
