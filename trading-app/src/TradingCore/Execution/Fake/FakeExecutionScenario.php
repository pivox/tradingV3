<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Fake;

final readonly class FakeExecutionScenario
{
    private const ORDER_OUTCOMES = ['accepted', 'rejected', 'cancelled'];
    private const PROTECTION_OUTCOMES = ['not_requested', 'attached', 'failed', 'rejected'];

    /**
     * @param list<string> $qualityFlags
     */
    public function __construct(
        public string $name,
        public string $orderOutcome,
        public float $fillRatio,
        public string $protectionOutcome,
        public ?string $rejectReason = null,
        public array $qualityFlags = [],
        public ?string $failSafeAction = null,
    ) {
        if (!in_array($this->orderOutcome, self::ORDER_OUTCOMES, true)) {
            throw new \InvalidArgumentException('Unsupported fake execution order outcome.');
        }

        if (!in_array($this->protectionOutcome, self::PROTECTION_OUTCOMES, true)) {
            throw new \InvalidArgumentException('Unsupported fake execution protection outcome.');
        }

        if ($this->fillRatio < 0.0 || $this->fillRatio > 1.0) {
            throw new \InvalidArgumentException('Fake execution fill ratio must be between 0 and 1.');
        }
    }
}
