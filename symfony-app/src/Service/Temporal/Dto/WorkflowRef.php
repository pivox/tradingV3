<?php
// src/Service/Temporal/Dto/WorkflowRef.php
namespace App\Service\Temporal\Dto;

/** Référence d’un workflow Temporal */
final class WorkflowRef
{
    const NAMESPACE_DEFAULT = 'default';

    public function __construct(
        public readonly string $id,          // ex: "rate-limited-echo"
        public readonly string $type,        // ex: "ApiRateLimiterClient"
        public readonly string $taskQueue,    // ex: "api_rate_limiter_queue"
        public readonly string $namespace = self::NAMESPACE_DEFAULT,   // ex: "default"
    ) {}
}
