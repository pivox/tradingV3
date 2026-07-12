<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Exchange\Enum\ExchangePositionSide;
use App\Provider\Hyperliquid\HyperliquidNonceScope;

final readonly class HyperliquidCompensationContext
{
    private const ADDRESS_PATTERN = '/^0x[0-9a-f]{40}$/D';
    private const CLOID_PATTERN = '/^0x[0-9a-f]{32}$/D';
    private const OID_PATTERN = '/^[1-9][0-9]*$/D';

    public string $accountAddress;
    public string $symbol;
    public string $entryWireCloid;
    public ?string $entryExchangeOrderId;
    public string $closeClientOrderId;
    public string $correlationId;
    public string $marginMode;
    /** @var array<string, mixed> */
    public array $redactedAuditContext;

    /** @param array<string, mixed> $redactedAuditContext */
    public function __construct(
        string $accountAddress,
        public int $assetId,
        string $symbol,
        public ExchangePositionSide $positionSide,
        string $entryWireCloid,
        ?string $entryExchangeOrderId,
        public float $quantity,
        string $closeClientOrderId,
        public HyperliquidNonceScope $nonceScope,
        string $correlationId,
        string $marginMode,
        public int $leverage,
        public float $emergencyCloseSlippageCapPrice,
        array $redactedAuditContext,
    ) {
        $this->accountAddress = strtolower(trim($accountAddress));
        $this->symbol = strtoupper(trim($symbol));
        $this->entryWireCloid = strtolower(trim($entryWireCloid));
        $this->entryExchangeOrderId = $entryExchangeOrderId === null ? null : trim($entryExchangeOrderId);
        $this->closeClientOrderId = strtolower(trim($closeClientOrderId));
        $this->correlationId = trim($correlationId);
        $this->marginMode = strtolower(trim($marginMode));

        if (preg_match(self::ADDRESS_PATTERN, $this->accountAddress) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_compensation_account_invalid');
        }
        if ($assetId < 0) {
            throw new \InvalidArgumentException('hyperliquid_compensation_asset_id_invalid');
        }
        if ($this->symbol === '' || strlen($this->symbol) > 32) {
            throw new \InvalidArgumentException('hyperliquid_compensation_symbol_invalid');
        }
        if (preg_match(self::CLOID_PATTERN, $this->entryWireCloid) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_compensation_entry_cloid_invalid');
        }
        if ($this->entryExchangeOrderId !== null) {
            $oid = filter_var($this->entryExchangeOrderId, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (preg_match(self::OID_PATTERN, $this->entryExchangeOrderId) !== 1
                || !is_int($oid)
                || (string) $oid !== $this->entryExchangeOrderId
            ) {
                throw new \InvalidArgumentException('hyperliquid_compensation_entry_oid_invalid');
            }
        }
        if (!is_finite($quantity) || $quantity <= 0.0) {
            throw new \InvalidArgumentException('hyperliquid_compensation_quantity_invalid');
        }
        if (preg_match(self::CLOID_PATTERN, $this->closeClientOrderId) !== 1
            || $this->closeClientOrderId === $this->entryWireCloid
        ) {
            throw new \InvalidArgumentException('hyperliquid_compensation_close_client_id_invalid');
        }
        if ($nonceScope->accountAddress !== $this->accountAddress
            || $nonceScope->environment !== 'testnet'
            || $nonceScope->network !== 'testnet'
            || preg_match(self::ADDRESS_PATTERN, $nonceScope->signerAddress) !== 1
            || $nonceScope->signerAddress === $this->accountAddress
        ) {
            throw new \InvalidArgumentException('hyperliquid_compensation_nonce_scope_invalid');
        }
        if ($this->correlationId === '' || strlen($this->correlationId) > 128) {
            throw new \InvalidArgumentException('hyperliquid_compensation_correlation_id_invalid');
        }
        if (!in_array($this->marginMode, ['cross', 'isolated'], true) || $leverage <= 0) {
            throw new \InvalidArgumentException('hyperliquid_compensation_margin_leverage_invalid');
        }
        if (!is_finite($emergencyCloseSlippageCapPrice) || $emergencyCloseSlippageCapPrice <= 0.0) {
            throw new \InvalidArgumentException('hyperliquid_compensation_close_price_invalid');
        }

        $this->redactedAuditContext = (new HyperliquidKillSwitchAuditSanitizer())->sanitizeContext(array_replace(
            $redactedAuditContext,
            ['correlation_id' => $this->correlationId],
        ));
    }
}
