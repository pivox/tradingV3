<?php

namespace App\Service\Trading;

use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use App\Repository\ContractRepository;
use App\Service\Bitmart\Private\PositionsService;
use App\Service\Bitmart\Private\OrdersService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OpenedLockedSyncService
{
    public function __construct(
        private readonly PositionsService $positionsService,
        private readonly OrdersService $ordersService,
        private readonly ContractPipelineRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly ContractRepository $contractRepository,
        private readonly LoggerInterface $logger
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
     *   changed_to_opened_locked?:string[],
     *   created_pipelines_pending?:string[],
     *   added_default_tp_sl?:string[],
     *   tp_sl_orders?:array<string, array{tp: array<string,mixed>|null, sl: array<string,mixed>|null}>,
     *   total_unlocked:int
     * }
     */
    public function sync(): array
    {
        $this->logger->info('[OpenedLockedSync] Démarrage synchronisation');
        // (1) Symboles effectivement ouverts chez BitMart
        $bm = $this->positionsService->list();
        $openPositions = [];
        $openSymbols = [];
        foreach (($bm['data'] ?? []) as $pos) {
            $qty = (float)($pos['current_amount'] ?? $pos['position_amount'] ?? 0);
            if ($qty !== 0.0) {
                $symbol = strtoupper((string)$pos['symbol']);
                $openSymbols[] = $symbol;
                // stocker la position par symbole (on garde la dernière si plusieurs entrées; ici mode one-way supposé)
                $openPositions[$symbol] = $pos;
            }
        }
        $openSymbols = array_values(array_unique($openSymbols));
        $this->logger->info('[OpenedLockedSync] Positions ouvertes détectées', ['symbols' => $openSymbols]);

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
                $this->logger->info('[OpenedLockedSync] Déverrouillage pipeline (plus de position active)', [
                    'symbol' => $sym,
                    'status_before' => $pipeline->getStatus(),
                ]);
                $pipeline
                    ->setStatus(ContractPipeline::STATUS_PENDING)
                    ->setIsValid1m(false)
                    ->setIsValid15m(false)
                    ->setIsValid1h(false)
                    ->setIsValid4h(false)
                    ->setCurrentTimeframe('4h');
                $this->em->persist($pipeline);
                $removed[] = $sym;
                if ($orderId !== null) {
                    $clearedOrderIds[] = $sym;
                }
                $requiresFlush = true;
            } else {
                if ($orderId !== null && !$orderStillOpen) {
                    $this->logger->info('[OpenedLockedSync] Nettoyage orderId (ordre plus ouvert)', [
                        'symbol' => $sym,
                        'order_id' => $orderId,
                    ]);
                    $pipeline->setOrderId(null);
                    $this->em->persist($pipeline);
                    $clearedOrderIds[] = $sym;
                    $requiresFlush = true;
                }
                $kept[] = $sym;
            }
        }

        // (4) Marquer / créer les pipelines pour les symboles réellement ouverts qui ne sont pas encore OPENED_LOCKED
        $changedToLocked = [];
        $createdPending = [];
        foreach ($openSymbols as $sym) {
            if (in_array($sym, $kept, true)) {
                // déjà recensé dans $kept -> on continue quand même pour la vérification TP/SL plus bas
            }
            $contract = $this->contractRepository->find($sym);
            if (!$contract) {
                $this->logger->warning('[OpenedLockedSync] Symbole ouvert inconnu localement — ignoré', ['symbol' => $sym]);
                continue;
            }
            $pipeline = $contract->getContractPipeline();
            if ($pipeline) {
                if ($pipeline->getStatus() !== ContractPipeline::STATUS_OPENED_LOCKED) {
                    $this->logger->info('[OpenedLockedSync] Passage en OPENED_LOCKED', [
                        'symbol' => $sym,
                        'status_before' => $pipeline->getStatus(),
                    ]);
                    $pipeline->setStatus(ContractPipeline::STATUS_OPENED_LOCKED);
                    $this->em->persist($pipeline);
                    $changedToLocked[] = $sym;
                    $requiresFlush = true;
                }
            } else {
                $this->logger->info('[OpenedLockedSync] Création pipeline PENDING (détection position sans pipeline)', [
                    'symbol' => $sym,
                ]);
                $pipeline = (new ContractPipeline())
                    ->setContract($contract)
                    ->setCurrentTimeframe('4h')
                    ->setStatus(ContractPipeline::STATUS_PENDING);
                $this->em->persist($pipeline);
                $createdPending[] = $sym;
                $requiresFlush = true;
            }
        }

        // (5) FLUSH éventuel des changements de statut avant création d'ordres TP/SL
        if ($requiresFlush) {
            $this->logger->info('[OpenedLockedSync] Flush des changements (statuts avant TP/SL)');
            $this->em->flush();
            $requiresFlush = false; // on réutilise pour les éventuels updates ultérieurs
        }

        // (6) Vérification / récupération / création TP & SL par défaut (5% SL, 10% TP) si manquants
        $addedTpSl = [];
        $tpSlOrders = []; // symbol => ['tp'=>?, 'sl' => ?]
        foreach ($openSymbols as $sym) {
            try {
                $ordersResp = $this->ordersService->open(['symbol' => $sym]);
            } catch (\Throwable $e) {
                $this->logger->warning('[OpenedLockedSync] Impossible de récupérer les ordres ouverts pour TP/SL', [
                    'symbol' => $sym,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            $planOrders = [];
            if (isset($ordersResp['plan_orders'])) {
                $planOrders = $this->normalizeOrders($ordersResp['plan_orders']);
            } elseif (isset($ordersResp['data']['plan_orders'])) {
                $planOrders = $this->normalizeOrders($ordersResp['data']['plan_orders']);
            }

            $hasTp = false; $hasSl = false; $tpOrder = null; $slOrder = null;
            foreach ($planOrders as $po) {
                $type = strtolower((string)($po['type'] ?? ''));
                if ($type === 'take_profit') { $hasTp = true; $tpOrder = $po; }
                if ($type === 'stop_loss') { $hasSl = true; $slOrder = $po; }
                if ($hasTp && $hasSl) break;
            }

            $pos = $openPositions[$sym] ?? null;
            if (!$pos) {
                $tpSlOrders[$sym] = ['tp' => $tpOrder, 'sl' => $slOrder];
                continue; // sécurité
            }

            // si déjà présents on enregistre seulement
            if ($hasTp && $hasSl) {
                $tpSlOrders[$sym] = ['tp' => $tpOrder, 'sl' => $slOrder];
                continue; // rien à créer
            }

            $entry = (float)($pos['entry_price'] ?? 0.0);
            if ($entry <= 0) {
                $entry = (float)($pos['open_avg_price'] ?? 0.0);
            }
            if ($entry <= 0) {
                $entry = (float)($pos['mark_price'] ?? 0.0);
            }
            if ($entry <= 0) {
                $this->logger->warning('[OpenedLockedSync] Entry price indisponible pour créer TP/SL', ['symbol' => $sym]);
                $tpSlOrders[$sym] = ['tp' => $tpOrder, 'sl' => $slOrder];
                continue;
            }
            $side = strtolower((string)($pos['position_side'] ?? 'long'));
            $size = (int)($pos['position_amount'] ?? $pos['current_amount'] ?? 0);
            if ($size <= 0) {
                $this->logger->warning('[OpenedLockedSync] Taille position nulle pour TP/SL', ['symbol' => $sym]);
                $tpSlOrders[$sym] = ['tp' => $tpOrder, 'sl' => $slOrder];
                continue;
            }
            // Règles : 5% SL, 10% TP
            if ($side === 'long') {
                $sl = $entry * (1 - 0.05);
                $tp = $entry * (1 + 0.10);
            } else { // short
                $sl = $entry * (1 + 0.05);
                $tp = $entry * (1 - 0.10);
            }
            // Quantisation simple via precision contrat
            $contract = $this->contractRepository->find($sym);
            if (!$contract) {
                $tpSlOrders[$sym] = ['tp' => $tpOrder, 'sl' => $slOrder];
                continue;
            }
            $tick = (float)$contract->getPricePrecision();
            if ($tick > 0) {
                $sl = $this->quantizePrice($sl, $tick, $side === 'long' ? 'down' : 'up');
                $tp = $this->quantizePrice($tp, $tick, $side === 'long' ? 'down' : 'up');
            }
            $reduceSide = $side === 'long' ? 3 : 2; // map reduce-only (fermer long => sell reduce=3, fermer short => buy reduce=2)
            // Création conditionnelle
            if (!$hasTp) {
                $tpCreation = $this->createPlanOrderSafe($sym, 'take_profit', $reduceSide, $tp, $size);
            }
            if (!$hasSl) {
                $slCreation = $this->createPlanOrderSafe($sym, 'stop_loss', $reduceSide, $sl, $size);
            }
            $addedTpSl[] = $sym;
            // on re-récupère rapidement (optionnel) ou on enregistre les valeurs calculées
            if (!$hasTp) {
                $tpOrder = [
                    'calculated_price' => $tp,
                    'size' => $size,
                    'type' => 'take_profit',
                    'order_id' => $tpCreation['order_id'] ?? null,
                    'client_order_id' => $tpCreation['client_order_id'] ?? null,
                ];
            }
            if (!$hasSl) {
                $slOrder = [
                    'calculated_price' => $sl,
                    'size' => $size,
                    'type' => 'stop_loss',
                    'order_id' => $slCreation['order_id'] ?? null,
                    'client_order_id' => $slCreation['client_order_id'] ?? null,
                ];
            }
            $tpSlOrders[$sym] = ['tp' => $tpOrder, 'sl' => $slOrder];
        }

        $summary = [
            'bitmart_open_symbols' => $openSymbols,
            'locked_symbols_before' => array_values(array_unique($lockedSymbolsBefore)),
            'removed_symbols' => array_values(array_unique($removed)),
            'kept_symbols' => array_values(array_unique($kept)),
            'cleared_order_ids' => array_values(array_unique($clearedOrderIds)),
            'changed_to_opened_locked' => array_values(array_unique($changedToLocked)),
            'created_pipelines_pending' => array_values(array_unique($createdPending)),
            'added_default_tp_sl' => array_values(array_unique($addedTpSl)),
            'tp_sl_orders' => $tpSlOrders,
            'total_unlocked' => count($removed),
        ];
        $this->logger->info('[OpenedLockedSync] Résumé synchronisation', $summary);
        return $summary;
    }

    /**
     * Crée un ordre plan (TP ou SL) et retourne un tableau avec order_id et client_order_id si succès.
     * @return array{order_id?:string, client_order_id?:string}|null
     */
    private function createPlanOrderSafe(string $symbol, string $type, int $sideReduce, float $price, int $size): ?array
    {
        $payload = [
            'symbol' => $symbol,
            'side' => $sideReduce,
            'type' => $type,            // 'take_profit' | 'stop_loss'
            'size' => $size,
            'trigger_price' => (string)$price,
            'executive_price' => (string)$price,
            'price_type' => 1,          // 1 = last (aligné exemple fourni)
            'category' => 'limit',      // aligné exemple
            'plan_category' => 1,       // aligné exemple
            'client_order_id' => strtoupper(substr($type,0,2)) . '_AUTO_' . bin2hex(random_bytes(4)),
        ];
        try {
            $this->logger->info('[OpenedLockedSync] Création plan order auto TP/SL', [
                'symbol' => $symbol,
                'type' => $type,
                'price' => $price,
                'size' => $size,
                'payload' => $payload,
            ]);
            $res = $this->ordersService->createPlan($payload);
            $code = (int)($res['code'] ?? 0);
            if ($code !== 1000) {
                $this->logger->warning('[OpenedLockedSync] Échec création plan order', [
                    'symbol' => $symbol,
                    'type' => $type,
                    'response' => $res,
                ]);
                return null;
            }
            $orderId = (string)($res['data']['order_id'] ?? $res['data']['orderId'] ?? '');
            $clientOrderId = (string)$payload['client_order_id'];
            $this->logger->info('[OpenedLockedSync] Plan order créé', [
                'symbol' => $symbol,
                'type' => $type,
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
            ]);
            return array_filter([
                'order_id' => $orderId ?: null,
                'client_order_id' => $clientOrderId ?: null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[OpenedLockedSync] Exception création plan order', [
                'symbol' => $symbol,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function quantizePrice(float $price, float $tick, string $direction = 'nearest'): float
    {
        if ($tick <= 0) return $price;
        $quot = $price / $tick;
        return match($direction) {
            'down' => floor($quot) * $tick,
            'up' => ceil($quot) * $tick,
            'nearest' => round($quot) * $tick,
            default => round($quot) * $tick,
        };
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
