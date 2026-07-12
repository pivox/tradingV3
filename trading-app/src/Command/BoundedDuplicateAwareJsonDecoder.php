<?php

declare(strict_types=1);

namespace App\Command;

final class BoundedDuplicateAwareJsonDecoder
{
    private string $json = '';
    private int $length = 0;
    private int $offset = 0;
    private int $tokens = 0;

    public function __construct(
        private readonly int $maxDepth = 16,
        private readonly int $maxTokens = 10_000,
    ) {
        if ($maxDepth < 1 || $maxTokens < 1) {
            throw new \InvalidArgumentException('json_bounds_invalid');
        }
    }

    /** @return array<mixed> */
    public function decode(string $json): array
    {
        $this->json = $json;
        $this->length = strlen($json);
        $this->offset = 0;
        $this->tokens = 0;

        $this->skipWhitespace();
        $this->parseValue(0);
        $this->skipWhitespace();
        if ($this->offset !== $this->length) {
            throw new \InvalidArgumentException('json_trailing_data');
        }

        try {
            $decoded = json_decode($json, true, $this->maxDepth + 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('json_invalid');
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('json_root_object_required');
        }

        return $decoded;
    }

    private function parseValue(int $depth): void
    {
        $this->skipWhitespace();
        $char = $this->json[$this->offset] ?? null;
        match ($char) {
            '{' => $this->parseObject($depth),
            '[' => $this->parseArray($depth),
            '"' => $this->parseString(),
            't' => $this->parseLiteral('true'),
            'f' => $this->parseLiteral('false'),
            'n' => $this->parseLiteral('null'),
            default => $this->parseNumber(),
        };
    }

    private function parseObject(int $depth): void
    {
        $this->assertDepth($depth);
        $this->consume('{');
        $this->bumpToken();
        $this->skipWhitespace();
        if ($this->peek('}')) {
            $this->consume('}');
            $this->bumpToken();
            return;
        }

        $seen = [];
        while (true) {
            $this->skipWhitespace();
            if (!$this->peek('"')) {
                throw new \InvalidArgumentException('json_object_key_invalid');
            }
            $key = $this->parseString();
            $identity = 'key:' . $key;
            if (isset($seen[$identity])) {
                throw new \InvalidArgumentException('json_duplicate_object_member');
            }
            $seen[$identity] = true;
            $this->skipWhitespace();
            $this->consume(':');
            $this->parseValue($depth + 1);
            $this->skipWhitespace();
            if ($this->peek('}')) {
                $this->consume('}');
                $this->bumpToken();
                return;
            }
            $this->consume(',');
        }
    }

    private function parseArray(int $depth): void
    {
        $this->assertDepth($depth);
        $this->consume('[');
        $this->bumpToken();
        $this->skipWhitespace();
        if ($this->peek(']')) {
            $this->consume(']');
            $this->bumpToken();
            return;
        }

        while (true) {
            $this->parseValue($depth + 1);
            $this->skipWhitespace();
            if ($this->peek(']')) {
                $this->consume(']');
                $this->bumpToken();
                return;
            }
            $this->consume(',');
        }
    }

    private function parseString(): string
    {
        $start = $this->offset;
        $this->consume('"');
        while ($this->offset < $this->length) {
            $char = $this->json[$this->offset++];
            if ($char === '"') {
                $lexeme = substr($this->json, $start, $this->offset - $start);
                try {
                    $decoded = json_decode($lexeme, true, 2, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \InvalidArgumentException('json_string_invalid');
                }
                if (!is_string($decoded)) {
                    throw new \InvalidArgumentException('json_string_invalid');
                }
                $this->bumpToken();

                return $decoded;
            }
            if ($char === '\\') {
                if ($this->offset >= $this->length) {
                    throw new \InvalidArgumentException('json_string_invalid');
                }
                ++$this->offset;
                continue;
            }
            if (ord($char) < 0x20) {
                throw new \InvalidArgumentException('json_string_invalid');
            }
        }

        throw new \InvalidArgumentException('json_string_unterminated');
    }

    private function parseNumber(): void
    {
        $start = $this->offset;
        if ($this->peek('-')) {
            ++$this->offset;
        }
        if ($this->peek('0')) {
            ++$this->offset;
            if ($this->isDigit($this->json[$this->offset] ?? null)) {
                throw new \InvalidArgumentException('json_number_invalid');
            }
        } elseif ($this->isDigitOneToNine($this->json[$this->offset] ?? null)) {
            do {
                ++$this->offset;
            } while ($this->isDigit($this->json[$this->offset] ?? null));
        } else {
            throw new \InvalidArgumentException('json_value_invalid');
        }
        if ($this->peek('.')) {
            ++$this->offset;
            if (!$this->isDigit($this->json[$this->offset] ?? null)) {
                throw new \InvalidArgumentException('json_number_invalid');
            }
            do {
                ++$this->offset;
            } while ($this->isDigit($this->json[$this->offset] ?? null));
        }
        $exponent = $this->json[$this->offset] ?? null;
        if ($exponent === 'e' || $exponent === 'E') {
            ++$this->offset;
            $sign = $this->json[$this->offset] ?? null;
            if ($sign === '+' || $sign === '-') {
                ++$this->offset;
            }
            if (!$this->isDigit($this->json[$this->offset] ?? null)) {
                throw new \InvalidArgumentException('json_number_invalid');
            }
            do {
                ++$this->offset;
            } while ($this->isDigit($this->json[$this->offset] ?? null));
        }
        if ($this->offset === $start) {
            throw new \InvalidArgumentException('json_number_invalid');
        }
        $this->bumpToken();
    }

    private function parseLiteral(string $literal): void
    {
        if (substr($this->json, $this->offset, strlen($literal)) !== $literal) {
            throw new \InvalidArgumentException('json_literal_invalid');
        }
        $this->offset += strlen($literal);
        $this->bumpToken();
    }

    private function assertDepth(int $depth): void
    {
        if ($depth >= $this->maxDepth) {
            throw new \InvalidArgumentException('json_depth_exceeded');
        }
    }

    private function bumpToken(): void
    {
        if (++$this->tokens > $this->maxTokens) {
            throw new \InvalidArgumentException('json_token_limit_exceeded');
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->offset < $this->length) {
            $char = $this->json[$this->offset];
            if ($char !== ' ' && $char !== "\t" && $char !== "\r" && $char !== "\n") {
                return;
            }
            ++$this->offset;
        }
    }

    private function consume(string $expected): void
    {
        if (!$this->peek($expected)) {
            throw new \InvalidArgumentException('json_syntax_invalid');
        }
        ++$this->offset;
    }

    private function peek(string $expected): bool
    {
        return ($this->json[$this->offset] ?? null) === $expected;
    }

    private function isDigit(?string $char): bool
    {
        return $char !== null && $char >= '0' && $char <= '9';
    }

    private function isDigitOneToNine(?string $char): bool
    {
        return $char !== null && $char >= '1' && $char <= '9';
    }
}
