<?php

namespace App\Controller\Web;

use App\Service\ContractDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class WebSocketDispatcherController extends AbstractController
{
    public function __construct(
        private readonly ContractDispatcher $dispatcher,
    ) {
    }

    #[Route('/ws/dispatch', name: 'ws_dispatch', methods: ['POST'])]
    public function dispatch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $symbols = $data['symbols'] ?? [];
        $strategy = $data['strategy'] ?? 'hash';
        $worker = $data['worker'] ?? null;
        $timeframes = $data['timeframes'] ?? ['1m', '5m', '15m', '1h', '4h'];
        $live = $data['live'] ?? true;

        if (empty($symbols)) {
            return new JsonResponse(['ok' => false, 'error' => 'No symbols provided'], 400);
        }

        try {
            if ($worker) {
                $assignments = $this->dispatcher->dispatchToWorker($symbols, $worker, $live, $timeframes);
                $message = "Dispatch vers {$worker} réussi pour " . count($symbols) . " contrats";
            } else {
                switch ($strategy) {
                    case 'hash':
                        $assignments = $this->dispatcher->dispatchByHash($symbols, $live, $timeframes);
                        $message = "Dispatch par hash réussi pour " . count($symbols) . " contrats";
                        break;
                    case 'least':
                        $capacity = $data['capacity'] ?? 20;
                        $assignments = $this->dispatcher->dispatchLeastLoaded($symbols, $capacity, $live, $timeframes);
                        $message = "Dispatch équilibré réussi pour " . count($symbols) . " contrats";
                        break;
                    default:
                        return new JsonResponse(['ok' => false, 'error' => 'Invalid strategy'], 400);
                }
            }

            return new JsonResponse([
                'ok' => true,
                'message' => $message,
                'assignments' => $assignments,
                'live' => $live
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ws/rebalance', name: 'ws_rebalance', methods: ['POST'])]
    public function rebalance(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $symbols = $data['symbols'] ?? [];
        $timeframes = $data['timeframes'] ?? ['1m', '5m', '15m', '1h', '4h'];
        $live = $data['live'] ?? true;

        if (empty($symbols)) {
            return new JsonResponse(['ok' => false, 'error' => 'No symbols provided'], 400);
        }

        try {
            $currentAssignments = $this->dispatcher->getCurrentAssignments();
            $moves = $this->dispatcher->rebalance($symbols, $currentAssignments, $live, $timeframes);

            return new JsonResponse([
                'ok' => true,
                'message' => "Rebalancement réussi. " . count($moves) . " symboles déplacés",
                'moves' => $moves,
                'live' => $live
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ws/assignments', name: 'ws_assignments', methods: ['GET'])]
    public function getAssignments(): JsonResponse
    {
        try {
            $assignments = $this->dispatcher->getCurrentAssignments();
            $workers = $this->dispatcher->getWorkers();

            // Calculer les statistiques par worker
            $stats = [];
            foreach ($workers as $worker) {
                $count = count(array_filter($assignments, fn($w) => $w === $worker));
                $stats[$worker] = $count;
            }

            return new JsonResponse([
                'ok' => true,
                'assignments' => $assignments,
                'workers' => $workers,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ws/subscribe', name: 'ws_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $tfs = $data['tfs'] ?? ['1m','5m','15m','1h','4h'];
        
        if (!$symbol || !\is_array($tfs)) {
            return new JsonResponse(['ok'=>false,'error'=>'bad_request'], 400);
        }
        
        try {
            // Utiliser le dispatcher pour envoyer la requête au ws-worker
            $this->dispatcher->dispatchToWorker([$symbol], $this->dispatcher->getWorkers()[0], true, $tfs);
            
            error_log("WebSocket Subscribe: {$symbol} -> " . implode(',', $tfs));
            
            // Note: Les statuts se mettront à jour via le bouton de rafraîchissement
            
            return new JsonResponse(['ok'=>true, 'message' => "Abonnement réussi pour {$symbol} sur " . implode(',', $tfs)]);
        } catch (\Exception $e) {
            error_log("WebSocket Subscribe Error: {$symbol} -> " . $e->getMessage());
            return new JsonResponse(['ok'=>false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ws/unsubscribe', name: 'ws_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $symbol = $data['symbol'] ?? '';
        $tfs = $data['tfs'] ?? ['1m','5m','15m','1h','4h'];
        
        if (!$symbol || !\is_array($tfs)) {
            return new JsonResponse(['ok'=>false,'error'=>'bad_request'], 400);
        }
        
        try {
            // Pour le désabonnement, on doit d'abord trouver le worker assigné
            $assignments = $this->dispatcher->getCurrentAssignments();
            $worker = $assignments[$symbol] ?? null;
            
            if ($worker) {
                // Envoyer la requête de désabonnement au worker assigné
                $this->dispatcher->postUnsubscribe($worker, $symbol, $tfs);
                
                // Supprimer l'assignation du CSV
                $assignments = $this->dispatcher->getCurrentAssignments();
                unset($assignments[$symbol]);
                $this->dispatcher->saveAssignments($assignments);
            }
            
            error_log("WebSocket Unsubscribe: {$symbol} -> " . implode(',', $tfs));
            
            // Note: Les statuts se mettront à jour via le bouton de rafraîchissement
            
            return new JsonResponse(['ok'=>true, 'message' => "Désabonnement réussi pour {$symbol} sur " . implode(',', $tfs)]);
        } catch (\Exception $e) {
            error_log("WebSocket Unsubscribe Error: {$symbol} -> " . $e->getMessage());
            return new JsonResponse(['ok'=>false, 'error' => $e->getMessage()], 500);
        }
    }
}
