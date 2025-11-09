<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Helper;

/**
 * Helper pour extraire les informations sur les ordres placés depuis les résultats MTF
 */
final class OrdersExtractor
{
    /**
     * Extrait tous les ordres placés (submitted et simulated) depuis les résultats MTF
     * 
     * @param array<string, array<string, mixed>> $results Résultats MTF par symbole
     * @return array<int, array{
     *     symbol: string,
     *     status: string,
     *     client_order_id: ?string,
     *     exchange_order_id: ?string,
     *     decision_key: ?string,
     *     raw: array
     * }>
     */
    public static function extractPlacedOrders(array $results): array
    {
        $orders = [];

        foreach ($results as $symbol => $info) {
            // Ignorer les entrées spéciales comme 'FINAL'
            if (!is_string($symbol) || $symbol === '' || $symbol === 'FINAL') {
                continue;
            }

            if (!is_array($info)) {
                continue;
            }

            // Extraire trading_decision depuis le résultat
            $tradingDecision = $info['trading_decision'] ?? null;
            if (!is_array($tradingDecision)) {
                continue;
            }

            $status = $tradingDecision['status'] ?? null;
            if (!is_string($status)) {
                continue;
            }

            // Inclure les ordres submitted et simulated
            if (!in_array($status, ['submitted', 'simulated'], true)) {
                continue;
            }

            $orders[] = [
                'symbol' => $symbol,
                'status' => $status,
                'client_order_id' => $tradingDecision['client_order_id'] ?? null,
                'exchange_order_id' => $tradingDecision['exchange_order_id'] ?? null,
                'decision_key' => $tradingDecision['decision_key'] ?? null,
                'raw' => $tradingDecision['raw'] ?? [],
            ];
        }

        return $orders;
    }

    /**
     * Extrait uniquement les ordres réellement soumis (exclut simulated)
     * 
     * @param array<string, array<string, mixed>> $results Résultats MTF par symbole
     * @return array<int, array{
     *     symbol: string,
     *     status: string,
     *     client_order_id: ?string,
     *     exchange_order_id: ?string,
     *     decision_key: ?string,
     *     raw: array
     * }>
     */
    public static function extractSubmittedOrders(array $results): array
    {
        $allOrders = self::extractPlacedOrders($results);
        return array_filter($allOrders, fn($order) => $order['status'] === 'submitted');
    }

    /**
     * Extrait uniquement les ordres simulés (dry-run)
     * 
     * @param array<string, array<string, mixed>> $results Résultats MTF par symbole
     * @return array<int, array{
     *     symbol: string,
     *     status: string,
     *     client_order_id: ?string,
     *     exchange_order_id: ?string,
     *     decision_key: ?string,
     *     raw: array
     * }>
     */
    public static function extractSimulatedOrders(array $results): array
    {
        $allOrders = self::extractPlacedOrders($results);
        return array_filter($allOrders, fn($order) => $order['status'] === 'simulated');
    }

    /**
     * Compte les ordres par statut
     * 
     * @param array<string, array<string, mixed>> $results Résultats MTF par symbole
     * @return array{total: int, submitted: int, simulated: int}
     */
    public static function countOrdersByStatus(array $results): array
    {
        $allOrders = self::extractPlacedOrders($results);
        $submitted = count(self::extractSubmittedOrders($results));
        $simulated = count(self::extractSimulatedOrders($results));

        return [
            'total' => count($allOrders),
            'submitted' => $submitted,
            'simulated' => $simulated,
        ];
    }
}

