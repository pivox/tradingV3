<?php

declare(strict_types=1);

namespace App\Front\ViewModel;

final readonly class FrontAlert
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $severity,
        public string $code,
        public string $title,
        public string $message,
        public ?string $symbol = null,
        public array $context = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'title' => $this->title,
            'message' => $this->message,
            'symbol' => $this->symbol,
            'context' => $this->context,
        ];
    }
}
