<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator;

/**
 * DTO représentant une erreur de validation individuelle
 */
final class ValidationError
{
    public function __construct(
        private readonly string $type,
        private readonly string $message,
        private readonly string $path,
        private readonly ?string $rule = null,
        private readonly array $context = []
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getRule(): ?string
    {
        return $this->rule;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'path' => $this->path,
            'rule' => $this->rule,
            'context' => $this->context,
        ];
    }

    public function __toString(): string
    {
        $str = sprintf('[%s] %s (path: %s', $this->type, $this->message, $this->path);
        if ($this->rule) {
            $str .= sprintf(', rule: %s', $this->rule);
        }
        if (!empty($this->context)) {
            $str .= sprintf(', context: %s', json_encode($this->context, JSON_UNESCAPED_SLASHES));
        }
        $str .= ')';
        return $str;
    }
}

