<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AsAlias(id: HyperliquidSignedActionClientInterface::class)]
final readonly class HttpHyperliquidSignedActionClient implements HyperliquidSignedActionClientInterface
{
    private const ALLOWED_BASE_URI = 'http://hyperliquid-signer:8098';
    private const ALLOWED_ACTIONS = ['order', 'cancel', 'cancelByCloid', 'updateLeverage'];
    private const MAX_BODY_BYTES = 65_536;
    private const MAX_STATUSES = 20;
    private const STABLE_REASON_PATTERN = '/^[a-z][a-z0-9_]{0,127}$/D';
    private const ADDRESS_PATTERN = '/^0x[0-9a-fA-F]{40}$/D';
    private const SENSITIVE_KEY_TOKENS = [
        'signature',
        'sign',
        'privatekey',
        'signing',
        'canonicalpayload',
        'credential',
        'token',
        'secret',
        'auth',
        'authorization',
        'password',
        'cookie',
        'passphrase',
        'apikey',
        'accesskey',
        'memo',
    ];

    private string $authToken;
    private string $accountAddress;
    private string $agentAddress;

    public function __construct(
        private HttpClientInterface $httpClient,
        string $baseUri,
        string $authToken,
        string $accountAddress,
        string $agentAddress,
    ) {
        if ($baseUri !== self::ALLOWED_BASE_URI) {
            throw new \InvalidArgumentException('hyperliquid_signer_endpoint_not_allowed');
        }
        if (trim($authToken) === '') {
            throw new \InvalidArgumentException('hyperliquid_signer_auth_token_required');
        }
        if (preg_match(self::ADDRESS_PATTERN, $accountAddress) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_signer_account_address_invalid');
        }
        if (preg_match(self::ADDRESS_PATTERN, $agentAddress) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_signer_agent_address_invalid');
        }

        $this->authToken = trim($authToken);
        $this->accountAddress = strtolower($accountAddress);
        $this->agentAddress = strtolower($agentAddress);
    }

    public function submit(
        array $action,
        int $nonce,
        string $correlationId,
        ?int $expiresAfter = null,
    ): HyperliquidSignedActionResult {
        $this->validateSubmission($action, $nonce, $correlationId, $expiresAfter);

        $payload = [
            'schema_version' => '1',
            'environment' => 'testnet',
            'network' => 'testnet',
            'account_address' => $this->accountAddress,
            'agent_address' => $this->agentAddress,
            'action' => $action,
            'nonce' => $nonce,
            'correlation_id' => $correlationId,
        ];
        if ($expiresAfter !== null) {
            $payload['expires_after'] = $expiresAfter;
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('hyperliquid_signer_action_invalid');
        }
        if (strlen($encoded) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('hyperliquid_signer_request_too_large');
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                self::ALLOWED_BASE_URI . '/v1/exchange',
                $this->requestOptions($encoded),
            );
            $statusCode = $response->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                return $this->result('rejected', 'signer_auth_failed', $correlationId);
            }
            if ($statusCode !== 200) {
                return $this->ambiguous($correlationId);
            }

            $body = $this->readBoundedBody($response);
            if ($body === null) {
                return $this->ambiguous($correlationId);
            }
        } catch (TransportExceptionInterface) {
            return $this->ambiguous($correlationId);
        }

        return $this->normalizeExchangeResponse($body, $correlationId);
    }

    public function health(): bool
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                self::ALLOWED_BASE_URI . '/v1/health',
                $this->requestOptions(),
            );
            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $body = $this->readBoundedBody($response);
            if ($body === null) {
                return false;
            }
        } catch (TransportExceptionInterface) {
            return false;
        }

        $payload = $this->decodeObject($body);
        if ($payload === null || !$this->hasExactKeys($payload, [
            'agent_address',
            'broadcast_enabled',
            'environment',
            'ready',
            'schema_version',
        ])) {
            return false;
        }

        return $payload['schema_version'] === '1'
            && $payload['ready'] === true
            && $payload['environment'] === 'testnet'
            && $payload['agent_address'] === $this->agentAddress
            && $payload['broadcast_enabled'] === true;
    }

    /** @return array<string, mixed> */
    private function requestOptions(?string $body = null): array
    {
        $options = [
            'headers' => ['Authorization: Bearer ' . $this->authToken],
            'timeout' => 5.0,
            'max_duration' => 5.0,
            'max_redirects' => 0,
            'proxy' => null,
            'no_proxy' => '*',
        ];
        if ($body !== null) {
            $options['headers'][] = 'Content-Type: application/json';
            $options['body'] = $body;
        }

        return $options;
    }

    /** @param array<string, mixed> $action */
    private function validateSubmission(
        array $action,
        int $nonce,
        string $correlationId,
        ?int $expiresAfter,
    ): void {
        if ($nonce <= 0) {
            throw new \InvalidArgumentException('hyperliquid_signer_nonce_invalid');
        }
        if ($expiresAfter !== null && $expiresAfter <= 0) {
            throw new \InvalidArgumentException('hyperliquid_signer_expires_after_invalid');
        }
        if (!isset($action['type']) || !is_string($action['type']) || !in_array($action['type'], self::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException('hyperliquid_signer_action_not_allowed');
        }
        if (trim($correlationId) === '' || strlen($correlationId) > 128) {
            throw new \InvalidArgumentException('hyperliquid_signer_correlation_id_invalid');
        }
    }

    private function readBoundedBody(ResponseInterface $response): ?string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                return null;
            }
            $content = $chunk->getContent();
            if (strlen($body) + strlen($content) > self::MAX_BODY_BYTES) {
                return null;
            }
            $body .= $content;
        }

        return $body;
    }

    private function normalizeExchangeResponse(string $body, string $correlationId): HyperliquidSignedActionResult
    {
        $payload = $this->decodeObject($body);
        if ($payload === null || !$this->hasExactKeys($payload, [
            'correlation_id',
            'outcome',
            'schema_version',
            'statuses',
        ], ['reason'])) {
            return $this->ambiguous($correlationId);
        }
        if ($payload['schema_version'] !== '1'
            || !is_string($payload['outcome'])
            || !in_array($payload['outcome'], ['accepted', 'rejected', 'ambiguous'], true)
            || $payload['correlation_id'] !== $correlationId
        ) {
            return $this->ambiguous($correlationId);
        }

        $reason = $payload['reason'] ?? null;
        if ($reason !== null && (!is_string($reason) || preg_match(self::STABLE_REASON_PATTERN, $reason) !== 1)) {
            return $this->ambiguous($correlationId);
        }

        $statuses = $this->normalizeStatuses($payload['statuses']);
        if ($statuses === null) {
            return $this->ambiguous($correlationId);
        }

        return new HyperliquidSignedActionResult(
            $payload['outcome'],
            $statuses,
            $reason,
            $correlationId,
        );
    }

    /** @return array<string, mixed>|null */
    private function decodeObject(string $body): ?array
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $decoded instanceof \stdClass ? get_object_vars($decoded) : null;
    }

    /** @return list<array<string, mixed>>|null */
    private function normalizeStatuses(mixed $statuses): ?array
    {
        if (!is_array($statuses) || !array_is_list($statuses) || count($statuses) > self::MAX_STATUSES) {
            return null;
        }

        $normalized = [];
        foreach ($statuses as $status) {
            if (!$status instanceof \stdClass) {
                return null;
            }
            $value = $this->normalizeStatusValue($status);
            if (!is_array($value)) {
                return null;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function normalizeStatusValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $child) {
                if ($this->isSensitiveKey($key)) {
                    return null;
                }
                $normalized[$key] = $this->normalizeStatusValue($child);
                if ($child !== null && $normalized[$key] === null) {
                    return null;
                }
            }

            return $normalized;
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $child) {
                $normalizedChild = $this->normalizeStatusValue($child);
                if ($child !== null && $normalizedChild === null) {
                    return null;
                }
                $normalized[] = $normalizedChild;
            }

            return $normalized;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($key));
        if (!is_string($normalized)) {
            return true;
        }
        foreach (self::SENSITIVE_KEY_TOKENS as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $requiredKeys
     * @param list<string> $optionalKeys
     */
    private function hasExactKeys(array $payload, array $requiredKeys, array $optionalKeys = []): bool
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        sort($requiredKeys);
        if ($actualKeys === $requiredKeys) {
            return true;
        }

        $allowedKeys = array_values(array_unique([...$requiredKeys, ...$optionalKeys]));
        sort($allowedKeys);

        return $actualKeys === $allowedKeys;
    }

    private function ambiguous(string $correlationId): HyperliquidSignedActionResult
    {
        return $this->result('ambiguous', 'signer_response_invalid', $correlationId);
    }

    private function result(string $outcome, string $reason, string $correlationId): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult($outcome, [], $reason, $correlationId);
    }
}
