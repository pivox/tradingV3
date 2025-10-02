<?php

namespace App\Service\Trading;

use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use App\Service\Bitmart\Private\PositionsService;
use App\Service\Bitmart\Private\OrdersService;
use Doctrine\ORM\EntityManagerInterface;

final class OpenedLockedSyncService
{
    public function __construct(
        private readonly PositionsService $positionsService,
        private readonly OrdersService $ordersService,
        private readonly ContractPipelineRepository $repo,
        private readonly EntityManagerInterface $em
    ) {}
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $openOrdersCache = [];

    /**
     * 1) Récupère les positions ouvertes sur BitMart (symboles avec qty != 0).
     * 2) Liste les pipelines en OPENED_LOCKED.
     * 3) Déverrouille (status -> pending) ceux qui ne sont plus ouverts.
     *
     * @return array{
     *   bitmart_open_symbols:string[],
     *   locked_symbols_before:string[],
     *   removed_symbols:string[],
     *   kept_symbols:string[],
     *   cleared_order_ids:string[],
     *   total_unlocked:int
     * }
     */
    public function sync(): array
    {
        // (1) Symboles effectivement ouverts chez BitMart
        $bm = $this->positionsService->list();
        $openSymbols = [];
        foreach (($bm['data'] ?? []) as $pos) {
            $qty = (float)($pos['current_amount'] ?? 0);
            if ($qty !== 0.0) {
                $openSymbols[] = strtoupper((string)$pos['symbol']);
            }
        }
        $openSymbols = array_values(array_unique($openSymbols));

        // (2) Pipelines OPENED_LOCKED
        $lockedPipelines = $this->repo->findAllOpenedLocked();
        $lockedSymbolsBefore = [];
        foreach ($lockedPipelines as $p) {
            $lockedSymbolsBefore[] = strtoupper($p->getContract()->getSymbol());
        }

        // (3) Déverrouiller ceux qui ne sont plus ouverts côté BitMart
        $removed = [];
        $kept = [];
        $clearedOrderIds = [];
        $requiresFlush = false;

        foreach ($lockedPipelines as $pipeline) {
            $sym = strtoupper($pipeline->getContract()->getSymbol());
            $orderId = $pipeline->getOrderId();
            $orderStillOpen = $orderId ? $this->isOrderStillOpen($sym, $orderId) : false;

            if (!in_array($sym, $openSymbols, true) && !$orderStillOpen) {
                // ⇒ position fermée et aucun ordre actif : on relâche la pipeline
                $pipeline->setStatus(ContractPipeline::STATUS_PENDING)->setOrderId(null);
                $this->em->persist($pipeline);
                $removed[] = $sym;
                if ($orderId !== null) {
                    $clearedOrderIds[] = $sym;
                }
                $requiresFlush = true;
            } else {
                if ($orderId !== null && !$orderStillOpen) {
                    // Ordre annulé ou exécuté, on efface la référence locale
                    $pipeline->setOrderId(null);
                    $this->em->persist($pipeline);
                    $clearedOrderIds[] = $sym;
                    $requiresFlush = true;
                }
                $kept[] = $sym;
            }
        }

        if ($requiresFlush) {
            $this->em->flush();
        }

        return [
            'bitmart_open_symbols' => $openSymbols,
            'locked_symbols_before' => array_values(array_unique($lockedSymbolsBefore)),
            'removed_symbols' => array_values(array_unique($removed)), // ceux qu’on a “sortis du tableau”
            'kept_symbols' => array_values(array_unique($kept)),       // ceux qui restent OPENED_LOCKED
            'cleared_order_ids' => array_values(array_unique($clearedOrderIds)),
            'total_unlocked' => count($removed),
        ];
    }

    private function isOrderStillOpen(string $symbol, string $orderId): bool
    {
        if ($orderId === '') {
            return false;
        }

        if (!array_key_exists($symbol, $this->openOrdersCache)) {
            try {
                $response = $this->ordersService->open(['symbol' => $symbol]);
            } catch (\Throwable) {
                // Impossible de déterminer, on suppose que l'ordre reste actif.
                return true;
            }

            $orders = $this->normalizeOrders($response['orders'] ?? []);
            $planOrders = $this->normalizeOrders($response['plan_orders'] ?? []);
            $this->openOrdersCache[$symbol] = array_merge($orders, $planOrders);
        }

        foreach ($this->openOrdersCache[$symbol] as $order) {
            if (!is_array($order)) {
                continue;
            }

            $matchesOrderId = isset($order['order_id']) && (string) $order['order_id'] === $orderId;
            $matchesClientOrderId = isset($order['client_order_id']) && (string) $order['client_order_id'] === $orderId;
            $matchesClientOid = isset($order['client_oid']) && (string) $order['client_oid'] === $orderId;

            if ($matchesOrderId || $matchesClientOrderId || $matchesClientOid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOrders(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $this->normalizeOrders($response['data']);
        }

        if ($this->isList($response)) {
            return array_filter($response, 'is_array');
        }

        if (isset($response['orders']) && is_array($response['orders'])) {
            return $this->normalizeOrders($response['orders']);
        }

        $orders = [];
        foreach ($response as $entry) {
            if (is_array($entry) && (isset($entry['order_id']) || isset($entry['client_order_id']) || isset($entry['client_oid']))) {
                $orders[] = $entry;
            }
        }

        return $orders;
    }

    private function isList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }
        return true;
    }
}
