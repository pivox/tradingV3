<?php

namespace App\Service\Temporal\Dto;

final class EnvelopeFactory
{
    public static function make(
        string $callback,           // URL absolue ou chemin (si $baseUrl est fourni)
        array $params,
        string $method = 'POST',
        ?string $baseUrl = null,
        string $encoding = 'form'   // 'form' par défaut (compatible Symfony)
    ): array {
        $isAbs = str_starts_with($callback, 'http://') || str_starts_with($callback, 'https://');
        $url   = $isAbs ? $callback : rtrim((string)$baseUrl, '/') . '/' . ltrim($callback, '/');

        return [
            'url_callback' => $url,
            'method'       => strtoupper($method),
            'encoding'     => strtolower($encoding), // 'form' ou 'json'
            'params'       => $params,
        ];
    }
}
