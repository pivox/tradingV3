<?php
declare(strict_types=1);

namespace App\TradeEntry\Exception;

final class EntryZoneOutOfBoundsException extends \RuntimeException
{
    public const REASON = 'skipped_out_of_zone';

    /** @var array<string,mixed> */
    private array $context;

    private ?string $customReason = null;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(string $message = 'Entry zone out of bounds', array $context = [], ?\Throwable $previous = null, ?string $reason = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
        $this->customReason = $reason;
    }

    /** @return array<string,mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getReason(): string
    {
        return $this->customReason ?? self::REASON;
    }
}

