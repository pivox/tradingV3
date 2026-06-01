<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OrderIntent;
use App\Entity\OrderProtection;
use App\Provider\Context\ExchangeContext;
use App\Repository\OrderIntentRepository;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OrderIntentManager
{
    private const BLOCKING_STATUSES = [
        OrderIntent::STATUS_DRAFT,
        OrderIntent::STATUS_VALIDATED,
        OrderIntent::STATUS_READY_TO_SEND,
        OrderIntent::STATUS_SENT,
        OrderIntent::STATUS_FAILED,
        OrderIntent::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly OrderIntentRepository $orderIntentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly DecisionKeyFactory $decisionKeyFactory,
        private readonly ?SymbolExecutionLockManager $symbolExecutionLockManager = null,
    ) {
    }

    /**
     * Crée un OrderIntent depuis les paramètres d'un ordre
     * @param array<string,mixed> $orderParams
     * @param array<string,mixed> $quantization
     * @param array<string,mixed>|null $rawInputs
     */
    public function createIntent(
        array $orderParams,
        array $quantization = [],
        ?array $rawInputs = null
    ): OrderIntent {
        $intent = $this->buildIntent($orderParams, $quantization, $rawInputs);

        $this->entityManager->persist($intent);
        $this->entityManager->flush();

        $this->logger->debug('[OrderIntentManager] Created intent', [
            'client_order_id' => $intent->getClientOrderId(),
            'exchange' => $intent->getExchange(),
            'market_type' => $intent->getMarketType(),
            'symbol' => $intent->getSymbol(),
            'status' => $intent->getStatus(),
        ]);

        return $intent;
    }

    /**
     * @param array<string,mixed> $orderParams
     * @param array<string,mixed> $quantization
     * @param array<string,mixed>|null $rawInputs
     */
    public function reserveIntent(
        array $orderParams,
        array $quantization = [],
        ?array $rawInputs = null,
    ): OrderIntentReservation {
        $decisionKey = isset($orderParams['decision_key']) ? trim((string) $orderParams['decision_key']) : '';
        if ($decisionKey === '') {
            $context = $this->contextFromParams($orderParams);
            $clientOrderId = isset($orderParams['client_order_id']) ? trim((string) $orderParams['client_order_id']) : '';
            if ($clientOrderId !== '') {
                $existing = $this->orderIntentRepository->findOneByClientOrderId($clientOrderId, $context);
                if ($existing instanceof OrderIntent) {
                    $this->logger->warning('[OrderIntentManager] Duplicate client_order_id replay blocked', [
                        'order_intent_id' => $existing->getId(),
                        'client_order_id' => $existing->getClientOrderId(),
                        'exchange_order_id' => $existing->getExchangeOrderId() ?? $existing->getOrderId(),
                        'status' => $existing->getStatus(),
                    ]);

                    return OrderIntentReservation::blocked($existing, 'idempotent_client_order_id_replay');
                }
            }

            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();
            try {
                $intent = $this->buildIntent($orderParams, $quantization, $rawInputs);
                $this->entityManager->persist($intent);

                if ($this->symbolExecutionLockManager instanceof SymbolExecutionLockManager) {
                    $lockReservation = $this->symbolExecutionLockManager->reserveForIntent(
                        $intent,
                        [
                            'source' => 'order_intent_reservation',
                            'decision_key' => null,
                            'strategy_profile' => $intent->getStrategyProfile(),
                            'strategy_version' => $intent->getStrategyVersion(),
                        ],
                    );

                    if ($lockReservation->blocked) {
                        $this->detachIntentGraph($intent);
                        $connection->commit();

                        $blockingIntent = $lockReservation->lock->getOwnerOrderIntent() ?? $intent;
                        $this->logger->warning('[OrderIntentManager] Global symbol lock blocked intent reservation', [
                            'exchange' => $intent->getExchange(),
                            'market_type' => $intent->getMarketType(),
                            'symbol' => $intent->getSymbol(),
                            'decision_key' => null,
                            'reason' => 'cross_profile_symbol_locked',
                            'lock' => $lockReservation->metadata['lock'] ?? null,
                        ]);

                        return OrderIntentReservation::blocked(
                            $blockingIntent,
                            'cross_profile_symbol_locked',
                            $lockReservation->metadata,
                        );
                    }
                }

                $this->entityManager->flush();
                $connection->commit();

                $this->logger->debug('[OrderIntentManager] Reserved intent', [
                    'order_intent_id' => $intent->getId(),
                    'client_order_id' => $intent->getClientOrderId(),
                    'decision_key' => $intent->getDecisionKey(),
                    'exchange' => $intent->getExchange(),
                    'market_type' => $intent->getMarketType(),
                    'symbol' => $intent->getSymbol(),
                    'status' => $intent->getStatus(),
                ]);

                return OrderIntentReservation::created($intent);
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $e;
            }
        }

        $context = $this->contextFromParams($orderParams);

        try {
            $connection = $this->entityManager->getConnection();
            $connection->beginTransaction();
            try {
                $this->lockDecisionKey($context, $decisionKey);

                $existing = $this->orderIntentRepository->findOneByDecisionKey($decisionKey, $context);
                if ($existing instanceof OrderIntent) {
                    $connection->commit();
                    return $this->reservationForExisting($existing);
                }

                $intent = $this->buildIntent($orderParams, $quantization, $rawInputs);
                $this->entityManager->persist($intent);

                if ($this->symbolExecutionLockManager instanceof SymbolExecutionLockManager) {
                    $lockReservation = $this->symbolExecutionLockManager->reserveForIntent(
                        $intent,
                        [
                            'source' => 'order_intent_reservation',
                            'decision_key' => $decisionKey,
                            'strategy_profile' => $intent->getStrategyProfile(),
                            'strategy_version' => $intent->getStrategyVersion(),
                        ],
                    );

                    if ($lockReservation->blocked) {
                        $this->detachIntentGraph($intent);
                        $connection->commit();

                        $blockingIntent = $lockReservation->lock->getOwnerOrderIntent() ?? $intent;
                        $this->logger->warning('[OrderIntentManager] Global symbol lock blocked intent reservation', [
                            'exchange' => $intent->getExchange(),
                            'market_type' => $intent->getMarketType(),
                            'symbol' => $intent->getSymbol(),
                            'decision_key' => $decisionKey,
                            'reason' => 'cross_profile_symbol_locked',
                            'lock' => $lockReservation->metadata['lock'] ?? null,
                        ]);

                        return OrderIntentReservation::blocked(
                            $blockingIntent,
                            'cross_profile_symbol_locked',
                            $lockReservation->metadata,
                        );
                    }
                }

                $this->entityManager->flush();
                $connection->commit();

                $this->logger->debug('[OrderIntentManager] Reserved intent', [
                    'order_intent_id' => $intent->getId(),
                    'client_order_id' => $intent->getClientOrderId(),
                    'decision_key' => $intent->getDecisionKey(),
                    'exchange' => $intent->getExchange(),
                    'market_type' => $intent->getMarketType(),
                    'symbol' => $intent->getSymbol(),
                    'status' => $intent->getStatus(),
                ]);

                return OrderIntentReservation::created($intent);
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $e;
            }
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->orderIntentRepository->findOneByDecisionKey($decisionKey, $context);
            if ($existing instanceof OrderIntent) {
                return $this->reservationForExisting($existing);
            }

            throw $e;
        }
    }

    public function findIntentById(int $id): ?OrderIntent
    {
        return $this->orderIntentRepository->findOneById($id);
    }

    /**
     * @param array<string,mixed> $orderParams
     * @return array<string,string>|null
     */
    public function validateOrderParams(array $orderParams): ?array
    {
        $errors = [];
        $type = strtolower((string)($orderParams['type'] ?? ''));

        if (trim((string)($orderParams['symbol'] ?? '')) === '') {
            $errors['symbol'] = 'Symbol is required';
        }
        if (!\in_array((int)($orderParams['side'] ?? 0), [1, 2, 3, 4], true)) {
            $errors['side'] = 'Invalid side value';
        }
        if (!\in_array($type, [OrderIntent::TYPE_LIMIT, OrderIntent::TYPE_MARKET], true)) {
            $errors['type'] = 'Invalid type value';
        }
        if ((int)($orderParams['size'] ?? 0) <= 0) {
            $errors['size'] = 'Size must be positive';
        }
        if ($type === OrderIntent::TYPE_LIMIT && trim((string)($orderParams['price'] ?? '')) === '') {
            $errors['price'] = 'Price is required for limit orders';
        }

        return $errors !== [] ? $errors : null;
    }

    /**
     * Valide un OrderIntent et le marque comme VALIDATED
     */
    public function validateIntent(OrderIntent $intent, ?array $errors = null): bool
    {
        if ($errors !== null && count($errors) > 0) {
            $intent->setValidationErrors($errors);
            $intent->markAsFailed('Validation failed: ' . json_encode($errors));
            $this->symbolExecutionLockManager?->releaseForIntent(
                $intent,
                'order_intent_validation_failed',
            );
            $this->entityManager->flush();
            return false;
        }

        $intent->markAsValidated();
        $intent->setValidationErrors(null);
        $this->entityManager->flush();

        $this->logger->debug('[OrderIntentManager] Validated intent', [
            'client_order_id' => $intent->getClientOrderId(),
            'symbol' => $intent->getSymbol(),
        ]);

        return true;
    }

    /**
     * Marque un OrderIntent comme READY_TO_SEND
     */
    public function markReadyToSend(OrderIntent $intent): void
    {
        $intent->markAsReadyToSend();
        $this->entityManager->flush();

        $this->logger->debug('[OrderIntentManager] Marked as ready to send', [
            'client_order_id' => $intent->getClientOrderId(),
            'symbol' => $intent->getSymbol(),
        ]);
    }

    /**
     * Marque un OrderIntent comme SENT après envoi réussi
     */
    public function markAsSent(OrderIntent $intent, string $orderId): void
    {
        $intent->markAsSent($orderId);
        $this->entityManager->flush();

        $this->logger->info('[OrderIntentManager] Marked as sent', [
            'client_order_id' => $intent->getClientOrderId(),
            'order_id' => $orderId,
            'symbol' => $intent->getSymbol(),
        ]);
    }

    /**
     * Marque un OrderIntent comme FAILED
     */
    public function markAsFailed(OrderIntent $intent, string $reason): void
    {
        $intent->markAsFailed($reason);
        $this->symbolExecutionLockManager?->releaseForIntent($intent, 'order_intent_failed');
        $this->entityManager->flush();

        $this->logger->warning('[OrderIntentManager] Marked as failed', [
            'client_order_id' => $intent->getClientOrderId(),
            'symbol' => $intent->getSymbol(),
            'reason' => $reason,
        ]);
    }

    /**
     * Marque un OrderIntent comme CANCELLED
     */
    public function markAsCancelled(OrderIntent $intent): void
    {
        $intent->markAsCancelled();
        $this->symbolExecutionLockManager?->releaseForIntent($intent, 'order_intent_cancelled', true);
        $this->entityManager->flush();

        $this->logger->info('[OrderIntentManager] Marked as cancelled', [
            'client_order_id' => $intent->getClientOrderId(),
            'symbol' => $intent->getSymbol(),
        ]);
    }

    /**
     * Trouve un OrderIntent par client_order_id ou order_id
     */
    public function findIntent(
        ?string $clientOrderId = null,
        ?string $orderId = null,
        ?ExchangeContext $context = null,
        ?string $decisionKey = null,
    ): ?OrderIntent
    {
        if ($clientOrderId !== null) {
            return $this->orderIntentRepository->findOneByClientOrderId($clientOrderId, $context);
        }

        if ($orderId !== null) {
            return $this->orderIntentRepository->findOneByOrderId($orderId, $context);
        }

        if ($decisionKey !== null) {
            return $this->orderIntentRepository->findOneByDecisionKey($decisionKey, $context);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $orderParams
     * @param array<string,mixed> $quantization
     * @param array<string,mixed>|null $rawInputs
     */
    private function buildIntent(
        array $orderParams,
        array $quantization = [],
        ?array $rawInputs = null,
    ): OrderIntent {
        $intent = new OrderIntent();

        $intent->setExchange((string)($orderParams['exchange'] ?? ExchangeContext::exchangeValue(null)));
        $intent->setMarketType((string)($orderParams['market_type'] ?? $orderParams['marketType'] ?? ExchangeContext::marketTypeValue(null)));
        $intent->setSymbol($orderParams['symbol'] ?? '');
        $intent->setSide((int)($orderParams['side'] ?? 1));
        $intent->setType($orderParams['type'] ?? OrderIntent::TYPE_LIMIT);
        $intent->setOpenType($orderParams['open_type'] ?? OrderIntent::OPEN_TYPE_ISOLATED);

        if (isset($orderParams['leverage'])) {
            $intent->setLeverage((int)$orderParams['leverage']);
        }

        $intent->setPositionMode(
            $orderParams['position_mode'] ?? OrderIntent::POSITION_MODE_ONE_WAY
        );

        if (isset($orderParams['price'])) {
            $intent->setPrice((string)$orderParams['price']);
        }

        $intent->setSize((int)($orderParams['size'] ?? 0));

        $clientOrderId = $orderParams['client_order_id']
            ?? $this->generateClientOrderId($intent->getSymbol());
        $intent->setClientOrderId((string) $clientOrderId);

        $intent->setPresetMode(
            $orderParams['preset_mode'] ?? OrderIntent::PRESET_MODE_NONE
        );

        $this->hydrateIdempotencyFields($intent, $orderParams);

        $intent->setQuantization($quantization);
        $intent->setRawInputs($rawInputs);
        $intent->setStatus(OrderIntent::STATUS_DRAFT);

        if (isset($orderParams['preset_take_profit_price'])) {
            $tp = new OrderProtection();
            $tp->setExchange($intent->getExchange());
            $tp->setMarketType($intent->getMarketType());
            $tp->setType(OrderProtection::TYPE_TAKE_PROFIT);
            $tp->setPrice((string)$orderParams['preset_take_profit_price']);
            $tp->setPriceType($orderParams['preset_take_profit_price_type'] ?? 1);
            $intent->addProtection($tp);
        }

        if (isset($orderParams['preset_stop_loss_price'])) {
            $sl = new OrderProtection();
            $sl->setExchange($intent->getExchange());
            $sl->setMarketType($intent->getMarketType());
            $sl->setType(OrderProtection::TYPE_STOP_LOSS);
            $sl->setPrice((string)$orderParams['preset_stop_loss_price']);
            $sl->setPriceType($orderParams['preset_stop_loss_price_type'] ?? 1);
            $intent->addProtection($sl);
        }

        return $intent;
    }

    /**
     * @param array<string,mixed> $orderParams
     */
    private function hydrateIdempotencyFields(OrderIntent $intent, array $orderParams): void
    {
        if (isset($orderParams['strategy_profile'])) {
            $intent->setStrategyProfile((string) $orderParams['strategy_profile']);
        }

        if (isset($orderParams['strategy_version'])) {
            $intent->setStrategyVersion((string) $orderParams['strategy_version']);
        }

        if (isset($orderParams['timeframe'])) {
            $intent->setTimeframe((string) $orderParams['timeframe']);
        }

        $decisionKey = isset($orderParams['decision_key']) ? trim((string) $orderParams['decision_key']) : '';
        if ($decisionKey === '') {
            return;
        }

        $intent->setDecisionKey($decisionKey);
        $parsed = $this->decisionKeyFactory->parse($decisionKey);

        if ($intent->getStrategyProfile() === null) {
            $intent->setStrategyProfile((string)($parsed['strategy_profile'] ?? ''));
        }

        if ($intent->getStrategyVersion() === null) {
            $intent->setStrategyVersion((string)($parsed['strategy_version'] ?? ''));
        }

        if ($intent->getTimeframe() === null) {
            $intent->setTimeframe((string)($parsed['timeframe'] ?? ''));
        }

        $candleOpenTs = $orderParams['candle_open_ts'] ?? $parsed['candle_open_ts'] ?? null;
        $timestamp = $this->decisionKeyFactory->normalizeCandleOpenTs($candleOpenTs, $intent->getTimeframe());
        $intent->setCandleOpenTs((new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC')));
    }

    /**
     * @param array<string,mixed> $orderParams
     */
    private function contextFromParams(array $orderParams): ExchangeContext
    {
        return ExchangeContext::fromValues(
            $orderParams['exchange'] ?? null,
            $orderParams['market_type'] ?? $orderParams['marketType'] ?? null,
        );
    }

    private function reservationForExisting(OrderIntent $existing): OrderIntentReservation
    {
        $reason = $this->blockingReason($existing);
        $this->logger->info('[OrderIntentManager] Existing intent found for decision key', [
            'order_intent_id' => $existing->getId(),
            'client_order_id' => $existing->getClientOrderId(),
            'exchange_order_id' => $existing->getExchangeOrderId() ?? $existing->getOrderId(),
            'decision_key' => $existing->getDecisionKey(),
            'status' => $existing->getStatus(),
            'reason' => $reason,
        ]);

        if ($reason !== null) {
            return OrderIntentReservation::blocked($existing, $reason);
        }

        return OrderIntentReservation::existing($existing);
    }

    private function blockingReason(OrderIntent $intent): ?string
    {
        if (!\in_array($intent->getStatus(), self::BLOCKING_STATUSES, true)) {
            return null;
        }

        return match ($intent->getStatus()) {
            OrderIntent::STATUS_FAILED => 'idempotent_failed_not_replayed',
            OrderIntent::STATUS_CANCELLED => 'idempotent_cancelled_not_replayed',
            OrderIntent::STATUS_SENT => 'idempotent_sent_replay',
            default => 'idempotent_in_flight',
        };
    }

    private function detachIntentGraph(OrderIntent $intent): void
    {
        foreach ($intent->getProtections() as $protection) {
            $this->entityManager->detach($protection);
        }

        $this->entityManager->detach($intent);
    }

    private function lockDecisionKey(ExchangeContext $context, string $decisionKey): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->getDatabasePlatform()->getName() !== 'postgresql') {
            return;
        }

        $connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:scope), hashtext(:decision_key))',
            [
                'scope' => $context->key(),
                'decision_key' => $decisionKey,
            ],
        );
    }

    /**
     * Génère un client_order_id unique
     */
    private function generateClientOrderId(string $symbol): string
    {
        $timestamp = (int)(microtime(true) * 1000);
        $random = bin2hex(random_bytes(4));
        return sprintf('INTENT_%s_%d_%s', $symbol, $timestamp, $random);
    }
}
