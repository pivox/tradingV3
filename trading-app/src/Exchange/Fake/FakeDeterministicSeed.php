<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeDeterministicSeed
{
    public const SCHEMA_VERSION = 'fake-deterministic-seed-v1';

    private string $seed;

    public function __construct(mixed $seed)
    {
        if (
            !\is_string($seed)
            || preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/D', $seed) !== 1
        ) {
            throw new \InvalidArgumentException('fake_deterministic_seed_invalid');
        }

        $this->seed = $seed;
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function fingerprint(): string
    {
        return 'sha256:' . hash('sha256', $this->seed);
    }

    /**
     * @param array<string,mixed> $components
     */
    public function deriveHex(string $domain, array $components): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,95}\z/D', $domain) !== 1) {
            throw new \InvalidArgumentException('fake_deterministic_seed_domain_invalid');
        }

        $payload = json_encode(
            $this->canonicalize($components),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_LINE_TERMINATORS
                | JSON_PRESERVE_ZERO_FRACTION,
        );

        return hash_hmac(
            'sha256',
            self::SCHEMA_VERSION . "\0" . $domain . "\0" . $payload,
            $this->seed,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value) || \is_string($value)) {
            return $value;
        }
        if (\is_float($value)) {
            throw new \InvalidArgumentException('fake_deterministic_seed_component_invalid');
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('fake_deterministic_seed_component_invalid');
        }
        if ($value === []) {
            throw new \InvalidArgumentException('fake_deterministic_seed_component_invalid');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        foreach (array_keys($value) as $key) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException('fake_deterministic_seed_component_invalid');
            }
        }
        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
