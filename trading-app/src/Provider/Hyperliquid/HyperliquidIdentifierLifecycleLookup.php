<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleNormalizer;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidNormalizedOrderLifecycleDto;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: HyperliquidIdentifierLifecycleLookupInterface::class)]
final readonly class HyperliquidIdentifierLifecycleLookup implements HyperliquidIdentifierLifecycleLookupInterface
{
    private const ADDRESS_PATTERN = '/^0x[0-9a-f]{40}$/D';
    private const CLOID_PATTERN = '/^0x[0-9a-f]{32}$/D';
    private const OID_PATTERN = '/^[1-9][0-9]*$/D';

    public function __construct(
        private HyperliquidRestClientInterface $restClient,
        private HyperliquidConfig $config,
        private HyperliquidLifecycleNormalizer $normalizer,
    ) {
    }

    public function lookup(
        string $accountAddress,
        string $identifier,
        ?string $expectedExchangeOrderId,
        string $expectedWireCloid,
    ): ?HyperliquidNormalizedOrderLifecycleDto {
        $accountAddress = strtolower(trim($accountAddress));
        $configuredAccount = $this->config->signingAccountAddress();
        if (preg_match(self::ADDRESS_PATTERN, $accountAddress) !== 1 || $accountAddress !== $configuredAccount) {
            throw new \InvalidArgumentException('hyperliquid_identifier_lookup_account_mismatch');
        }

        $identifier = strtolower(trim($identifier));
        $wireIdentifier = $this->wireIdentifier($identifier);
        $expectedWireCloid = strtolower(trim($expectedWireCloid));
        if (preg_match(self::CLOID_PATTERN, $expectedWireCloid) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_identifier_lookup_identifier_invalid');
        }
        if ($expectedExchangeOrderId !== null) {
            $this->wireIdentifier($expectedExchangeOrderId);
        }
        $response = $this->restClient->info([
            'type' => 'orderStatus',
            'user' => $accountAddress,
            'oid' => $wireIdentifier,
        ]);

        if (($response['status'] ?? null) === 'unknownOid') {
            if ($response !== ['status' => 'unknownOid']) {
                throw new \RuntimeException('hyperliquid_identifier_lookup_response_malformed');
            }

            return null;
        }

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$response]);
        $responseOid = $this->canonicalResponseOid($lifecycle->exchangeOrderId);
        $responseCloid = strtolower((string) $lifecycle->clientOrderId);
        $matches = $responseCloid === $expectedWireCloid
            && ($expectedExchangeOrderId === null || $responseOid === $expectedExchangeOrderId)
            && (is_int($wireIdentifier) ? $responseOid === (string) $wireIdentifier : $responseCloid === $wireIdentifier);
        if (!$matches) {
            throw new HyperliquidIdentifierBindingException();
        }

        return $lifecycle;
    }

    private function canonicalResponseOid(string $oid): string
    {
        $wire = $this->wireIdentifier($oid);
        if (!is_int($wire)) {
            throw new HyperliquidIdentifierBindingException();
        }

        return (string) $wire;
    }

    private function wireIdentifier(string $identifier): int|string
    {
        if (preg_match(self::CLOID_PATTERN, $identifier) === 1) {
            return $identifier;
        }
        if (preg_match(self::OID_PATTERN, $identifier) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_identifier_lookup_identifier_invalid');
        }

        $oid = filter_var($identifier, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($oid) || (string) $oid !== $identifier) {
            throw new \InvalidArgumentException('hyperliquid_identifier_lookup_identifier_invalid');
        }

        return $oid;
    }
}
