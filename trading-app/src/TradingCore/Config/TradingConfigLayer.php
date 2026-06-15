<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

final readonly class TradingConfigLayer
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $type,
        public string $name,
        public string $path,
        public bool $required,
        public array $config,
    ) {
    }

    /**
     * @return array{type: string, name: string, path: string, required: bool}
     */
    public function toLogContext(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'path' => $this->path,
            'required' => $this->required,
        ];
    }
}
