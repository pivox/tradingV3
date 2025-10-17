<?php

declare(strict_types=1);

namespace App\Domain\Trading\Order;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Ports\Out\TradingProviderPort;
use App\Domain\Trading\Exposure\ContractCooldownService;
use App\Entity\OrderLifecycle;
use App\Repository\OrderLifecycleRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class OrderLifecycleService
{
    private const KIND_ENTRY = 'ENTRY';
    private const KIND_STOP_LOSS = 'STOP_LOSS';
    private const KIND_TAKE_PROFIT = 'TAKE_PROFIT';

    public function __construct(
        private readonly OrderLifecycleRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TradingProviderPort $tradingProvider,
        private readonly ContractCooldownService $cooldownService,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $context
     */
    public function registerEntryOrder(array $order, array $context): void
    {
        $orderId = $this->extractOrderId($order);
        if ($orderId === null) {
            $this->logger->warning('[OrderLifecycle] Unable to register entry order without order_id', $order);
            return;
        }

        $symbol = strtoupper((string)($order['symbol'] ?? $context['symbol'] ?? ''));
        if ($symbol === '') {
            throw new RuntimeException('registerEntryOrder requires symbol in order payload');
        }

        $clientOrderId = $this->normalizeClientId($order['client_order_id'] ?? null);
        $side = isset($context['side']) && $context['side'] instanceof SignalSide
            ? $context['side']->value
            : (string)($context['side'] ?? null);

        $entity = $this->repository->findOneByOrderId($orderId) ?? new OrderLifecycle(
            $orderId,
            $symbol,
            'SUBMITTED',
            $clientOrderId,
            $side,
            $order['type'] ?? null,
            $this->clockNowUtc()
        );

        $payload = $entity->getPayload();
        $payload['context'] = array_merge($payload['context'] ?? [], $context);
        $payload['context']['kind'] = self::KIND_ENTRY;
        $payload['last_submission'] = $order;

        $entity
            ->setClientOrderId($clientOrderId)
            ->setSide($side)
            ->setType($order['type'] ?? null)
            ->replacePayload($payload);

        $this->persist($entity);
    }

    /**
     * @param array<string,mixed> $order
     */
    public function registerProtectiveOrder(array $order, string $kind): void
    {
        $orderId = $this->extractOrderId($order);
        if ($orderId === null) {
            return;
        }

        $symbol = strtoupper((string)($order['symbol'] ?? ''));
        if ($symbol === '') {
            throw new RuntimeException('registerProtectiveOrder requires symbol');
        }

        $clientOrderId = $this->normalizeClientId($order['client_order_id'] ?? null);

        $entity = $this->repository->findOneByOrderId($orderId) ?? new OrderLifecycle(
            $orderId,
            $symbol,
            'SUBMITTED',
            $clientOrderId,
            $order['side'] ?? null,
            $order['orderType'] ?? $order['type'] ?? null,
            $this->clockNowUtc()
        );

        $payload = $entity->getPayload();
        $payload['context'] = array_merge($payload['context'] ?? [], ['kind' => strtoupper($kind)]);
        $payload['last_submission'] = $order;

        $entity
            ->setClientOrderId($clientOrderId)
            ->setType($order['orderType'] ?? $order['type'] ?? null)
            ->mergePayload($payload);

        $this->persist($entity);
    }

    /**
     * @param array<string,mixed> $event Normalized event from Bitmart order stream
     */
    public function handleEvent(array $event): void
    {
        $orderId = (string)($event['order_id'] ?? '');
        if ($orderId === '') {
            return;
        }

        $entity = $this->repository->findOneByOrderId($orderId);
        if ($entity === null) {
            $symbol = strtoupper((string)($event['symbol'] ?? ''));
            if ($symbol === '') {
                $this->logger->warning('[OrderLifecycle] Dropping event without symbol', $event);
                return;
            }

            $entity = new OrderLifecycle(
                $orderId,
                $symbol,
                'UNKNOWN',
                $this->normalizeClientId($event['client_order_id'] ?? null),
                (string)($event['side'] ?? null),
                $event['type'] ?? null,
                $this->clockNowUtc()
            );
        }

        $status = $this->determineStatus($event);
        $entity
            ->setStatus($status, $this->eventTimestamp($event))
            ->setLastAction($this->determineAction($event))
            ->setType($event['type'] ?? $entity->getType())
            ->mergePayload([
                'last_event' => $event,
            ]);

        if (($event['client_order_id'] ?? null) !== null) {
            $entity->setClientOrderId($this->normalizeClientId($event['client_order_id']));
        }

        $this->persist($entity);

        if ($status === 'FILLED' || $status === 'PARTIALLY_FILLED') {
            $this->onOrderFilled($entity, $status, $event);
        }

        if ($status === 'CANCELLED') {
            $this->logger->info('[OrderLifecycle] Order cancelled', [
                'symbol' => $entity->getSymbol(),
                'order_id' => $entity->getOrderId(),
                'client_order_id' => $entity->getClientOrderId(),
            ]);
        }
    }

    private function determineStatus(array $event): string
    {
        $action = (int)($event['action'] ?? 0);
        $state = (int)($event['state'] ?? 0);
        $size = (float)($event['size'] ?? 0.0);
        $dealSize = (float)($event['deal_size'] ?? 0.0);

        if (in_array($action, [3, 4, 5], true)) {
            return 'CANCELLED';
        }

        if ($dealSize >= $size && $size > 0.0) {
            return 'FILLED';
        }

        if ($dealSize > 0.0) {
            return 'PARTIALLY_FILLED';
        }

        return match ($state) {
            1 => 'PENDING',
            2 => 'ACKNOWLEDGED',
            4 => 'FINISHED',
            default => 'UPDATED',
        };
    }

    private function determineAction(array $event): ?string
    {
        return match ((int)($event['action'] ?? 0)) {
            1 => 'MATCH_DEAL',
            2 => 'SUBMIT',
            3 => 'CANCEL',
            4 => 'LIQUIDATE_CANCEL',
            5 => 'ADL_CANCEL',
            6 => 'PART_LIQUIDATE',
            7 => 'BANKRUPTCY',
            8 => 'PASSIVE_ADL_MATCH',
            9 => 'ACTIVE_ADL_MATCH',
            default => null,
        };
    }

    private function onOrderFilled(OrderLifecycle $order, string $status, array $event): void
    {
        $kind = $this->determineKind($order);

        if ($kind === self::KIND_ENTRY) {
            $this->ensureProtectiveOrders($order);
            return;
        }

        if ($kind === self::KIND_STOP_LOSS || $kind === self::KIND_TAKE_PROFIT) {
            if ($status === 'FILLED') {
                $this->logger->info('[OrderLifecycle] Protective order filled, starting cooldown', [
                    'symbol' => $order->getSymbol(),
                    'kind' => $kind,
                ]);
                $this->cooldownService->startCooldown($order->getSymbol(), new DateInterval('PT4H'), strtolower($kind));
            }
        }
    }

    private function ensureProtectiveOrders(OrderLifecycle $entryOrder): void
    {
        $context = $entryOrder->getPayload()['context'] ?? [];
        $symbol = $entryOrder->getSymbol();

        if (!isset($context['stop_loss_price'], $context['take_profit_price'], $context['size'])) {
            $this->logger->warning('[OrderLifecycle] Missing context to enforce protective orders', [
                'symbol' => $symbol,
                'order_id' => $entryOrder->getOrderId(),
            ]);
            return;
        }

        $openOrders = $this->tradingProvider->getOpenOrders($symbol);
        $planOrders = $openOrders['plan_orders'] ?? [];

        $hasStop = $this->hasPlanOrder($planOrders, $entryOrder->getClientOrderId(), self::KIND_STOP_LOSS);
        $hasTakeProfit = $this->hasPlanOrder($planOrders, $entryOrder->getClientOrderId(), self::KIND_TAKE_PROFIT);

        if ($hasStop && $hasTakeProfit) {
            return;
        }

        $payload = $entryOrder->getPayload();
        $submission = $payload['last_submission'] ?? [];
        $symbolUpper = strtoupper($symbol);
        $size = $this->toStringSize((float)$context['size']);
        $side = strtoupper((string)($context['side'] ?? ''));
        $closeSide = $side === SignalSide::LONG->value ? 3 : 2;

        if (!$hasStop) {
            $this->logger->warning('[OrderLifecycle] Stop loss missing, re-submitting', [
                'symbol' => $symbolUpper,
            ]);
            $payloadSl = [
                'symbol' => $symbolUpper,
                'orderType' => 'stop_loss',
                'type' => 'stop_loss',
                'side' => $closeSide,
                'triggerPrice' => $this->formatPrice((float)$context['stop_loss_price']),
                'executivePrice' => $this->formatPrice((float)$context['stop_loss_price']),
                'priceType' => 2,
                'plan_category' => '2',
                'category' => 'limit',
                'size' => (string) max(1, (int) round((float)$context['size'])),
                'client_order_id' => $this->deriveClientId($entryOrder->getClientOrderId(), 'SL'),
            ];
            $resp = $this->tradingProvider->submitTpSlOrder($payloadSl);
            $orderId = $resp['data']['order_id'] ?? null;
            if ($orderId !== null) {
                $payloadSl['order_id'] = $orderId;
                $this->registerProtectiveOrder($payloadSl, self::KIND_STOP_LOSS);
            }
            $this->logger->info('[OrderLifecycle] Stop loss re-submitted', ['response' => $resp]);
        }

        if (!$hasTakeProfit) {
            $this->logger->warning('[OrderLifecycle] Take profit missing, re-submitting', [
                'symbol' => $symbolUpper,
            ]);
            $payloadTp = [
                'symbol' => $symbolUpper,
                'orderType' => 'take_profit',
                'type' => 'take_profit',
                'side' => $closeSide,
                'triggerPrice' => $this->formatPrice((float)$context['take_profit_price']),
                'executivePrice' => $this->formatPrice((float)$context['take_profit_price']),
                'priceType' => 2,
                'plan_category' => '2',
                'category' => 'limit',
                'size' => (string) max(1, (int) round((float)$context['size'])),
                'client_order_id' => $this->deriveClientId($entryOrder->getClientOrderId(), 'TP'),
            ];
            $resp = $this->tradingProvider->submitTpSlOrder($payloadTp);
            $orderId = $resp['data']['order_id'] ?? null;
            if ($orderId !== null) {
                $payloadTp['order_id'] = $orderId;
                $this->registerProtectiveOrder($payloadTp, self::KIND_TAKE_PROFIT);
            }
            $this->logger->info('[OrderLifecycle] Take profit re-submitted', ['response' => $resp]);
        }
    }

    /**
     * @param array<int,mixed> $planOrders
     */
    private function hasPlanOrder(array $planOrders, ?string $entryClientId, string $kind): bool
    {
        foreach ($planOrders as $order) {
            if (!\is_array($order)) {
                continue;
            }

            $clientId = $this->normalizeClientId($order['client_order_id'] ?? null);
            if ($clientId === null) {
                continue;
            }

            if ($kind === self::KIND_STOP_LOSS && str_contains($clientId, '_SL_')) {
                return true;
            }

            if ($kind === self::KIND_TAKE_PROFIT && str_contains($clientId, '_TP_')) {
                return true;
            }

            if ($entryClientId !== null && str_contains($clientId, $entryClientId)) {
                return true;
            }
        }

        return false;
    }

    private function determineKind(OrderLifecycle $order): string
    {
        $context = $order->getPayload()['context'] ?? [];
        if (isset($context['kind'])) {
            return strtoupper((string)$context['kind']);
        }

        $clientId = $order->getClientOrderId();
        if ($clientId !== null) {
            if (str_contains($clientId, '_SL_')) {
                return self::KIND_STOP_LOSS;
            }
            if (str_contains($clientId, '_TP_')) {
                return self::KIND_TAKE_PROFIT;
            }
            if (str_contains($clientId, '_OPEN_')) {
                return self::KIND_ENTRY;
            }
        }

        return self::KIND_ENTRY;
    }

    private function deriveClientId(?string $baseClientId, string $suffix): string
    {
        if ($baseClientId === null || $baseClientId === '') {
            return sprintf('MTF_%s_%s_%s', uniqid('SYM'), strtoupper($suffix), bin2hex(random_bytes(4)));
        }

        if (preg_match('/^(MTF_[A-Z0-9]+)_([A-Z]+)_(.+)$/', $baseClientId, $m)) {
            return sprintf('%s_%s_%s', $m[1], strtoupper($suffix), $m[3]);
        }

        return $baseClientId . '_' . strtoupper($suffix);
    }

    private function extractOrderId(array $order): ?string
    {
        $orderId = $order['order_id'] ?? $order['orderId'] ?? null;
        if ($orderId === null) {
            return null;
        }

        $orderId = (string) $orderId;
        return $orderId === '' ? null : $orderId;
    }

    private function normalizeClientId(mixed $clientId): ?string
    {
        if ($clientId === null) {
            return null;
        }

        $clientId = (string) $clientId;
        return $clientId === '' ? null : strtoupper($clientId);
    }

    private function persist(OrderLifecycle $order): void
    {
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    private function eventTimestamp(array $event): DateTimeImmutable
    {
        $updateTime = (int)($event['update_time_ms'] ?? $event['update_time'] ?? 0);
        if ($updateTime > 0) {
            if ($updateTime > 9_999_999_999) { // ms
                $seconds = (int) floor($updateTime / 1000);
            } else {
                $seconds = $updateTime;
            }
            return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
        }

        return $this->clockNowUtc();
    }

    private function clockNowUtc(): DateTimeImmutable
    {
        $now = $this->clock->now();
        return DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
    }

    private function toStringSize(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function formatPrice(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
