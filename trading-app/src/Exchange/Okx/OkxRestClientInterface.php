<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

interface OkxRestClientInterface
{
    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function publicGet(string $path, array $query = []): array;

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function privateGet(string $path, array $query = []): array;

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function privatePost(string $path, array $body = []): array;
}
