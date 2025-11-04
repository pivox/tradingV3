<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OrderIntent;
use App\Entity\OrderProtection;
use App\Repository\OrderIntentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OrderIntentManager
{
    public function __construct(
        private readonly OrderIntentRepository $orderIntentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
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
        $intent = new OrderIntent();

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
        
        // Générer un client_order_id unique s'il n'est pas fourni
        $clientOrderId = $orderParams['client_order_id'] 
            ?? $this->generateClientOrderId($intent->getSymbol());
        $intent->setClientOrderId($clientOrderId);

        $intent->setPresetMode(
            $orderParams['preset_mode'] ?? OrderIntent::PRESET_MODE_NONE
        );

        $intent->setQuantization($quantization);
        $intent->setRawInputs($rawInputs);
        $intent->setStatus(OrderIntent::STATUS_DRAFT);

        // Ajouter les protections TP/SL si présentes
        if (isset($orderParams['preset_take_profit_price'])) {
            $tp = new OrderProtection();
            $tp->setType(OrderProtection::TYPE_TAKE_PROFIT);
            $tp->setPrice((string)$orderParams['preset_take_profit_price']);
            $tp->setPriceType($orderParams['preset_take_profit_price_type'] ?? 1);
            $intent->addProtection($tp);
        }

        if (isset($orderParams['preset_stop_loss_price'])) {
            $sl = new OrderProtection();
            $sl->setType(OrderProtection::TYPE_STOP_LOSS);
            $sl->setPrice((string)$orderParams['preset_stop_loss_price']);
            $sl->setPriceType($orderParams['preset_stop_loss_price_type'] ?? 1);
            $intent->addProtection($sl);
        }

        $this->entityManager->persist($intent);
        $this->entityManager->flush();

        $this->logger->debug('[OrderIntentManager] Created intent', [
            'client_order_id' => $clientOrderId,
            'symbol' => $intent->getSymbol(),
            'status' => $intent->getStatus(),
        ]);

        return $intent;
    }

    /**
     * Valide un OrderIntent et le marque comme VALIDATED
     */
    public function validateIntent(OrderIntent $intent, ?array $errors = null): bool
    {
        if ($errors !== null && count($errors) > 0) {
            $intent->setValidationErrors($errors);
            $intent->markAsFailed('Validation failed: ' . json_encode($errors));
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
        $this->entityManager->flush();

        $this->logger->info('[OrderIntentManager] Marked as cancelled', [
            'client_order_id' => $intent->getClientOrderId(),
            'symbol' => $intent->getSymbol(),
        ]);
    }

    /**
     * Trouve un OrderIntent par client_order_id ou order_id
     */
    public function findIntent(?string $clientOrderId = null, ?string $orderId = null): ?OrderIntent
    {
        if ($clientOrderId !== null) {
            return $this->orderIntentRepository->findOneByClientOrderId($clientOrderId);
        }

        if ($orderId !== null) {
            return $this->orderIntentRepository->findOneByOrderId($orderId);
        }

        return null;
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

