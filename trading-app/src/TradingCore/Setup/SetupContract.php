<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

final readonly class SetupContract
{
    /** @param array<string, mixed> $document */
    private function __construct(
        public string $setupId,
        public string $setupVersion,
        public string $status,
        public string $side,
        private array $document,
    ) {
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(array $document, ?SetupContractValidator $validator = null): self
    {
        ($validator ?? new SetupContractValidator())->validate($document);

        return new self($document['setup_id'], $document['setup_version'], $document['status'], $document['side'], $document);
    }

    public function isExecutable(): bool
    {
        return $this->document['executable'] === true
            && !in_array($this->status, ['draft', 'blocked', 'retired'], true)
            && $this->unresolvedPaths() === []
            && $this->document['data_condition_contract']['missing_conditions'] === [];
    }

    public function stableHash(): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($this->document),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return list<string> */
    public function unresolvedPaths(): array
    {
        $paths = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$paths): void {
            if (($node['state'] ?? null) === 'unresolved') {
                $paths[] = $prefix;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value, $prefix === '' ? (string) $key : $prefix . '.' . $key);
                }
            }
        };
        $walk($this->document, '');

        return $paths;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
