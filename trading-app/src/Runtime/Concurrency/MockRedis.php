<?php

declare(strict_types=1);

namespace App\Runtime\Concurrency;

/**
 * Mock Redis pour les tests et développement sans extension Redis.
 * - Si l'extension Redis est présente, on étend \Redis pour compatibilité de signature.
 * - Sinon, on fournit une implémentation autonome aux types de retour assouplis.
 */
if (class_exists('Redis')) {
    class MockRedis extends \Redis
    {
        private array $data = [];

        public function connect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
        {
            return true;
        }

        public function get(string $key): string|false
        {
            return $this->data[$key] ?? false;
        }

        public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
        {
            $this->data[$key] = $value;
            return true;
        }

        public function del(array|string $key, string ...$other_keys): \Redis|int|false
        {
            if (is_string($key)) {
                if (isset($this->data[$key])) {
                    unset($this->data[$key]);
                    return 1;
                }
                return 0;
            }
            return 0;
        }

        public function exists(mixed $key, mixed ...$other_keys): \Redis|int|bool
        {
            if (is_string($key)) {
                return isset($this->data[$key]) ? 1 : 0;
            }
            return 0;
        }

        public function expire(string $key, int $timeout, ?string $mode = null): \Redis|bool
        {
            return true;
        }

        public function ttl(string $key): \Redis|int
        {
            return -1;
        }

        public function eval(string $script, array $args = [], int $numKeys = 0): \Redis|bool|int|string
        {
            return 1;
        }

        public function keys(string $pattern): \Redis|array|false
        {
            $keys = [];
            foreach (array_keys($this->data) as $k) {
                if (fnmatch($pattern, $k)) { $keys[] = $k; }
            }
            return $keys;
        }
    }
} else {
    class MockRedis
    {
        private array $data = [];

        public function connect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
        {
            return true;
        }

        public function get(string $key): string|false
        {
            return $this->data[$key] ?? false;
        }

        public function set(string $key, mixed $value, mixed $options = null): bool|string
        {
            $this->data[$key] = $value;
            return true;
        }

        public function del(array|string $key, string ...$other_keys): int|false
        {
            if (is_string($key)) {
                if (isset($this->data[$key])) { unset($this->data[$key]); return 1; }
                return 0;
            }
            return 0;
        }

        public function exists(mixed $key, mixed ...$other_keys): int|bool
        {
            if (is_string($key)) { return isset($this->data[$key]) ? 1 : 0; }
            return 0;
        }

        public function expire(string $key, int $timeout, ?string $mode = null): bool
        {
            return true;
        }

        public function ttl(string $key): int
        {
            return -1;
        }

        public function eval(string $script, array $args = [], int $numKeys = 0): bool|int|string
        {
            return 1;
        }

        public function keys(string $pattern): array|false
        {
            $keys = [];
            foreach (array_keys($this->data) as $k) { if (fnmatch($pattern, $k)) { $keys[] = $k; } }
            return $keys;
        }
    }
}
