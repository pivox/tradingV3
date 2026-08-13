<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Json;

final class StrictJsonObjectDecoder
{
    public const MAX_INPUT_BYTES = 8_388_608;

    private const MAX_JSON_DEPTH = 128;
    // The largest supported indicator projection request has 29,776 structural tokens:
    // 1m, 5m and 15m windows of 250 candles plus the 1,000 1h candles needed to derive 4h.
    // Keep a fixed, bounded headroom for envelope evolution while retaining a fail-closed limit.
    private const MAX_STRUCTURE_TOKENS = 32_768;

    /** @return array<string, mixed> */
    public function decode(#[\SensitiveParameter] string $payload): array
    {
        if (strlen($payload) > self::MAX_INPUT_BYTES) {
            throw new \InvalidArgumentException('input_too_large');
        }
        if (trim($payload) === '') {
            throw new \InvalidArgumentException('input_blank');
        }

        try {
            $this->assertNoDuplicateKeysAndBoundDepth($payload);
            $decoded = json_decode($payload, true, self::MAX_JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                $exception->getCode() === JSON_ERROR_DEPTH ? 'json_depth_exceeded' : 'json_invalid',
                previous: $exception,
            );
        }
        if (!\is_array($decoded) || ltrim($payload)[0] !== '{') {
            throw new \InvalidArgumentException('root_object_required');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function assertNoDuplicateKeysAndBoundDepth(string $json): void
    {
        /** @var list<array{type:'object'|'list',keys:array<string,true>,expecting_key:bool}> $stack */
        $stack = [];
        $structureTokens = 0;
        $length = strlen($json);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $json[$offset];
            if ($char === '"') {
                $start = $offset;
                for (++$offset; $offset < $length; ++$offset) {
                    if ($json[$offset] === '\\') {
                        ++$offset;
                        continue;
                    }
                    if ($json[$offset] === '"') {
                        break;
                    }
                }
                if ($offset >= $length) {
                    return;
                }
                $top = array_key_last($stack);
                if ($top === null || $stack[$top]['type'] !== 'object' || !$stack[$top]['expecting_key']) {
                    continue;
                }
                $lookahead = $offset + 1;
                while ($lookahead < $length && str_contains(" \t\r\n", $json[$lookahead])) {
                    ++$lookahead;
                }
                if ($lookahead >= $length || $json[$lookahead] !== ':') {
                    continue;
                }
                $key = json_decode(substr($json, $start, $offset - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                if (!\is_string($key)) {
                    return;
                }
                if (isset($stack[$top]['keys'][$key])) {
                    throw new \InvalidArgumentException('duplicate_object_key');
                }
                $stack[$top]['keys'][$key] = true;
                $stack[$top]['expecting_key'] = false;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $this->consumeStructureToken($structureTokens);
                $stack[] = [
                    'type' => $char === '{' ? 'object' : 'list',
                    'keys' => [],
                    'expecting_key' => $char === '{',
                ];
                if (\count($stack) > self::MAX_JSON_DEPTH) {
                    throw new \InvalidArgumentException('json_depth_exceeded');
                }
                continue;
            }
            if ($char === '}' || $char === ']') {
                array_pop($stack);
                continue;
            }
            if ($char === ',') {
                $this->consumeStructureToken($structureTokens);
                $top = array_key_last($stack);
                if ($top !== null && $stack[$top]['type'] === 'object') {
                    $stack[$top]['expecting_key'] = true;
                }
            }
        }
    }

    private function consumeStructureToken(int &$tokens): void
    {
        ++$tokens;
        if ($tokens > self::MAX_STRUCTURE_TOKENS) {
            throw new \InvalidArgumentException('json_structure_too_large');
        }
    }
}
