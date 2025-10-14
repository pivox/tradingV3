<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Service;

use App\Domain\PostValidation\Dto\PostValidationDecisionDto;
use App\Domain\PostValidation\Dto\EntryZoneDto;
use App\Domain\PostValidation\Dto\OrderPlanDto;
use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Infrastructure\Http\BitmartRestClient;
use App\Logging\PositionLogger;
use Psr\Log\LoggerInterface;

/**
 * Machine d'états pour l'orchestration des séquences Post-Validation
 * 
 * États : READY → ENTRY_ZONE_BUILT → SUBMITTED_MAKER → (FILLED | PARTIAL | TIMEOUT) 
 * → (ATTACH_TP_SL) → OPENED → MONITORING
 */
final class PostValidationStateMachine
{
    // États
    public const STATE_READY = 'READY';
    public const STATE_ENTRY_ZONE_BUILT = 'ENTRY_ZONE_BUILT';
    public const STATE_SUBMITTED_MAKER = 'SUBMITTED_MAKER';
    public const STATE_FILLED = 'FILLED';
    public const STATE_PARTIAL = 'PARTIAL';
    public const STATE_TIMEOUT = 'TIMEOUT';
    public const STATE_ATTACH_TP_SL = 'ATTACH_TP_SL';
    public const STATE_OPENED = 'OPENED';
    public const STATE_MONITORING = 'MONITORING';
    public const STATE_FAILED = 'FAILED';

    // Actions
    public const ACTION_BUILD_ENTRY_ZONE = 'BUILD_ENTRY_ZONE';
    public const ACTION_SUBMIT_MAKER = 'SUBMIT_MAKER';
    public const ACTION_WAIT_FILL = 'WAIT_FILL';
    public const ACTION_CANCEL_MAKER = 'CANCEL_MAKER';
    public const ACTION_SUBMIT_TAKER = 'SUBMIT_TAKER';
    public const ACTION_ATTACH_TP_SL = 'ATTACH_TP_SL';
    public const ACTION_OPEN_POSITION = 'OPEN_POSITION';
    public const ACTION_START_MONITORING = 'START_MONITORING';
    public const ACTION_FAIL = 'FAIL';

    private string $currentState = self::STATE_READY;
    private array $stateData = [];
    private array $transitions = [];

    public function __construct(
        private readonly BitmartRestClient $restClient,
        private readonly LoggerInterface $logger,
        private readonly PositionLogger $positionLogger
    ) {
        $this->initializeTransitions();
    }

    /**
     * Exécute une séquence E2E complète
     */
    public function executeSequence(
        EntryZoneDto $entryZone,
        OrderPlanDto $orderPlan,
        MarketDataDto $marketData
    ): PostValidationDecisionDto {
        $this->logger->info('[StateMachine] Starting E2E sequence', [
            'symbol' => $entryZone->symbol,
            'side' => $entryZone->side,
            'initial_state' => $this->currentState
        ]);

        // Log du début de la machine d'états
        $this->positionLogger->logStateMachineStart($entryZone->symbol, $entryZone->side, $this->currentState);

        try {
            // Séquence principale
            $this->transition(self::ACTION_BUILD_ENTRY_ZONE, ['entry_zone' => $entryZone]);
            $this->transition(self::ACTION_SUBMIT_MAKER, ['order_plan' => $orderPlan]);
            
            // Attendre le fill ou timeout
            $fillResult = $this->waitForFill(['order_plan' => $orderPlan]);
            
            if ($fillResult['status'] === 'FILLED') {
                $this->transition(self::ACTION_ATTACH_TP_SL, ['order_plan' => $orderPlan]);
                $this->transition(self::ACTION_OPEN_POSITION, ['order_plan' => $orderPlan]);
                $this->transition(self::ACTION_START_MONITORING, ['order_plan' => $orderPlan]);
                
                return $this->createSuccessDecision($entryZone, $orderPlan, $marketData, $fillResult);
            } elseif ($fillResult['status'] === 'TIMEOUT') {
                // Fallback vers taker
                $this->transition(self::ACTION_CANCEL_MAKER, ['order_plan' => $orderPlan]);
                $takerResult = $this->transition(self::ACTION_SUBMIT_TAKER, ['order_plan' => $orderPlan]);
                
                if ($takerResult['status'] === 'FILLED') {
                    $this->transition(self::ACTION_ATTACH_TP_SL, ['order_plan' => $orderPlan]);
                    $this->transition(self::ACTION_OPEN_POSITION, ['order_plan' => $orderPlan]);
                    $this->transition(self::ACTION_START_MONITORING, ['order_plan' => $orderPlan]);
                    
                    return $this->createSuccessDecision($entryZone, $orderPlan, $marketData, $takerResult);
                } else {
                    $this->transition(self::ACTION_FAIL, $takerResult);
                    return $this->createFailureDecision($entryZone, $orderPlan, $marketData, 'Taker order failed');
                }
            } else {
                $this->transition(self::ACTION_FAIL, $fillResult);
                return $this->createFailureDecision($entryZone, $orderPlan, $marketData, 'Maker order failed');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('[StateMachine] Sequence failed', [
                'symbol' => $entryZone->symbol,
                'error' => $e->getMessage(),
                'current_state' => $this->currentState
            ]);
            
            $this->transition(self::ACTION_FAIL, ['error' => $e->getMessage()]);
            return $this->createFailureDecision($entryZone, $orderPlan, $marketData, $e->getMessage());
        }
    }

    /**
     * Exécute une transition d'état
     */
    public function transition(string $action, array $data = []): array
    {
        if (!isset($this->transitions[$this->currentState][$action])) {
            throw new \InvalidArgumentException("Invalid transition: {$this->currentState} -> {$action}");
        }

        $transition = $this->transitions[$this->currentState][$action];
        $newState = $transition['to'];
        
        $this->logger->info('[StateMachine] Transition', [
            'from' => $this->currentState,
            'action' => $action,
            'to' => $newState
        ]);

        // Log de la transition
        $this->positionLogger->logStateTransition('', $this->currentState, $action, $newState, $data);

        // Exécuter l'action
        $result = $this->executeAction($action, $data);
        
        // Mettre à jour l'état
        $this->currentState = $newState;
        $this->stateData[$newState] = $result;

        return $result;
    }

    /**
     * Exécute une action spécifique
     */
    private function executeAction(string $action, array $data): array
    {
        return match ($action) {
            self::ACTION_BUILD_ENTRY_ZONE => $this->buildEntryZone($data),
            self::ACTION_SUBMIT_MAKER => $this->submitMakerOrder($data),
            self::ACTION_WAIT_FILL => $this->waitForFill($data),
            self::ACTION_CANCEL_MAKER => $this->cancelMakerOrder($data),
            self::ACTION_SUBMIT_TAKER => $this->submitTakerOrder($data),
            self::ACTION_ATTACH_TP_SL => $this->attachTpSl($data),
            self::ACTION_OPEN_POSITION => $this->openPosition($data),
            self::ACTION_START_MONITORING => $this->startMonitoring($data),
            self::ACTION_FAIL => $this->handleFailure($data),
            default => throw new \InvalidArgumentException("Unknown action: $action")
        };
    }

    /**
     * Construit la zone d'entrée
     */
    private function buildEntryZone(array $data): array
    {
        $entryZone = $data['entry_zone'] ?? null;
        if (!$entryZone instanceof EntryZoneDto) {
            throw new \InvalidArgumentException('EntryZoneDto expected in data');
        }
        
        $this->logger->info('[StateMachine] Building entry zone', [
            'symbol' => $entryZone->symbol,
            'side' => $entryZone->side,
            'entry_min' => $entryZone->entryMin,
            'entry_max' => $entryZone->entryMax
        ]);

        return [
            'status' => 'SUCCESS',
            'entry_zone' => $entryZone->toArray(),
            'timestamp' => time()
        ];
    }

    /**
     * Soumet l'ordre maker
     */
    private function submitMakerOrder(array $data): array
    {
        $orderPlan = $data['order_plan'] ?? null;
        if (!$orderPlan instanceof OrderPlanDto) {
            throw new \InvalidArgumentException('OrderPlanDto expected in data');
        }
        
        $this->logger->info('[StateMachine] Submitting maker order', [
            'symbol' => $orderPlan->symbol,
            'side' => $orderPlan->side,
            'client_order_id' => $orderPlan->clientOrderId
        ]);

        try {
            $makerOrder = $orderPlan->makerOrders[0] ?? null;
            if (!$makerOrder) {
                throw new \RuntimeException('No maker order in plan');
            }

            // TODO: Implémenter submitOrder dans BitmartRestClient
            // $response = $this->restClient->submitOrder($makerOrder);
            $response = ['order_id' => 'mock_order_id'];
            
            // Log de la soumission de l'ordre maker
            $this->positionLogger->logMakerOrderSubmitted(
                $orderPlan->symbol,
                $orderPlan->clientOrderId,
                $makerOrder,
                $response
            );
            
            return [
                'status' => 'SUBMITTED',
                'order_id' => $response['order_id'] ?? null,
                'client_order_id' => $orderPlan->clientOrderId,
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            $this->logger->error('[StateMachine] Maker order submission failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Attend le fill de l'ordre maker
     */
    private function waitForFill(array $data): array
    {
        $orderPlan = $data['order_plan'] ?? null;
        if (!$orderPlan instanceof OrderPlanDto) {
            throw new \InvalidArgumentException('OrderPlanDto expected in data');
        }
        
        $timeout = 5; // 5 secondes par défaut
        $startTime = time();
        
        $this->logger->info('[StateMachine] Waiting for fill', [
            'symbol' => $orderPlan->symbol,
            'timeout' => $timeout
        ]);

        // Log de l'attente du fill
        $this->positionLogger->logWaitingForFill($orderPlan->symbol, $orderPlan->clientOrderId, $timeout);

        // Simulation pour les tests
        $fillResult = [
            'status' => 'FILLED',
            'order_id' => 'mock_filled_order',
            'filled_qty' => $orderPlan->quantity,
            'avg_price' => $orderPlan->getEntryPrice(),
            'timestamp' => time()
        ];

        // Log du résultat du fill
        $this->positionLogger->logFillResult($orderPlan->symbol, $orderPlan->clientOrderId, $fillResult);

        return $fillResult;
    }

    /**
     * Annule l'ordre maker
     */
    private function cancelMakerOrder(array $data): array
    {
        $orderPlan = $data['order_plan'] ?? null;
        if (!$orderPlan instanceof OrderPlanDto) {
            throw new \InvalidArgumentException('OrderPlanDto expected in data');
        }
        
        $this->logger->info('[StateMachine] Cancelling maker order', [
            'symbol' => $orderPlan->symbol,
            'client_order_id' => $orderPlan->clientOrderId
        ]);

        $cancelResult = [
            'status' => 'CANCELLED',
            'order_id' => 'mock_cancelled_order',
            'timestamp' => time()
        ];

        // Log de l'annulation de l'ordre maker
        $this->positionLogger->logMakerOrderCancelled($orderPlan->symbol, $orderPlan->clientOrderId, $cancelResult);

        return $cancelResult;
    }

    /**
     * Soumet l'ordre taker (fallback)
     */
    private function submitTakerOrder(array $data): array
    {
        $orderPlan = $data['order_plan'] ?? null;
        if (!$orderPlan instanceof OrderPlanDto) {
            throw new \InvalidArgumentException('OrderPlanDto expected in data');
        }
        
        $this->logger->info('[StateMachine] Submitting taker order', [
            'symbol' => $orderPlan->symbol,
            'side' => $orderPlan->side
        ]);

        $takerOrder = $orderPlan->fallbackOrders[0] ?? null;
        if (!$takerOrder) {
            throw new \RuntimeException('No taker order in plan');
        }

        $takerResult = [
            'status' => 'FILLED', // Taker orders are typically filled immediately
            'order_id' => 'mock_taker_order',
            'filled_qty' => $takerOrder['quantity'],
            'avg_price' => $takerOrder['price'],
            'timestamp' => time()
        ];

        // Log de la soumission de l'ordre taker
        $this->positionLogger->logTakerOrderSubmitted($orderPlan->symbol, $takerOrder, $takerResult);

        return $takerResult;
    }

    /**
     * Attache les ordres TP/SL
     */
    private function attachTpSl(array $data): array
    {
        $orderPlan = $data['order_plan'] ?? null;
        if (!$orderPlan instanceof OrderPlanDto) {
            throw new \InvalidArgumentException('OrderPlanDto expected in data');
        }
        
        $this->logger->info('[StateMachine] Attaching TP/SL orders', [
            'symbol' => $orderPlan->symbol,
            'tp_sl_count' => count($orderPlan->tpSlOrders)
        ]);

        $results = [];
        foreach ($orderPlan->tpSlOrders as $tpSlOrder) {
            $results[] = [
                'type' => $tpSlOrder['type'],
                'status' => 'SUBMITTED',
                'order_id' => 'mock_tp_sl_order'
            ];
        }

        $tpSlResult = [
            'status' => 'COMPLETED',
            'tp_sl_results' => $results,
            'timestamp' => time()
        ];

        // Log de l'attachement des ordres TP/SL
        $this->positionLogger->logTpSlAttached($orderPlan->symbol, $results);

        return $tpSlResult;
    }

    /**
     * Ouvre la position
     */
    private function openPosition(array $data): array
    {
        $orderPlan = $data['order_plan'] ?? null;
        if (!$orderPlan instanceof OrderPlanDto) {
            throw new \InvalidArgumentException('OrderPlanDto expected in data');
        }
        
        $this->logger->info('[StateMachine] Opening position', [
            'symbol' => $orderPlan->symbol,
            'side' => $orderPlan->side,
            'quantity' => $orderPlan->quantity,
            'leverage' => $orderPlan->leverage
        ]);

        $positionData = [
            'status' => 'OPENED',
            'symbol' => $orderPlan->symbol,
            'side' => $orderPlan->side,
            'quantity' => $orderPlan->quantity,
            'leverage' => $orderPlan->leverage,
            'timestamp' => time()
        ];

        // Log de l'ouverture effective de la position
        $this->positionLogger->logPositionOpened(
            $orderPlan->symbol,
            $orderPlan->side,
            $orderPlan->quantity,
            $orderPlan->leverage,
            $orderPlan->getEntryPrice(),
            $positionData
        );

        return $positionData;
    }

    /**
     * Démarre le monitoring
     */
    private function startMonitoring(array $data): array
    {
        $orderPlan = $data['order_plan'] ?? null;
        if (!$orderPlan instanceof OrderPlanDto) {
            throw new \InvalidArgumentException('OrderPlanDto expected in data');
        }
        
        $this->logger->info('[StateMachine] Starting monitoring', [
            'symbol' => $orderPlan->symbol
        ]);

        $monitoringData = [
            'status' => 'MONITORING',
            'symbol' => $orderPlan->symbol,
            'timestamp' => time()
        ];

        // Log du démarrage du monitoring
        $this->positionLogger->logMonitoringStarted($orderPlan->symbol, $monitoringData);

        return $monitoringData;
    }

    /**
     * Gère l'échec
     */
    private function handleFailure(array $data): array
    {
        $this->logger->error('[StateMachine] Handling failure', $data);

        return [
            'status' => 'FAILED',
            'error' => $data['error'] ?? 'Unknown error',
            'timestamp' => time()
        ];
    }

    /**
     * Crée une décision de succès
     */
    private function createSuccessDecision(
        EntryZoneDto $entryZone,
        OrderPlanDto $orderPlan,
        MarketDataDto $marketData,
        array $executionResult
    ): PostValidationDecisionDto {
        return new PostValidationDecisionDto(
            decision: PostValidationDecisionDto::DECISION_OPEN,
            reason: 'Position opened successfully',
            entryZone: $entryZone,
            orderPlan: $orderPlan,
            marketData: $marketData->toArray(),
            guards: ['all_passed' => true],
            evidence: [
                'execution_result' => $executionResult,
                'state_machine_data' => $this->stateData,
                'final_state' => $this->currentState
            ],
            decisionKey: $orderPlan->decisionKey,
            timestamp: time()
        );
    }

    /**
     * Crée une décision d'échec
     */
    private function createFailureDecision(
        EntryZoneDto $entryZone,
        OrderPlanDto $orderPlan,
        MarketDataDto $marketData,
        string $reason
    ): PostValidationDecisionDto {
        return new PostValidationDecisionDto(
            decision: PostValidationDecisionDto::DECISION_SKIP,
            reason: $reason,
            entryZone: $entryZone,
            orderPlan: $orderPlan,
            marketData: $marketData->toArray(),
            guards: ['all_passed' => false],
            evidence: [
                'failure_reason' => $reason,
                'state_machine_data' => $this->stateData,
                'final_state' => $this->currentState
            ],
            decisionKey: $orderPlan->decisionKey,
            timestamp: time()
        );
    }

    /**
     * Initialise les transitions d'état
     */
    private function initializeTransitions(): void
    {
        $this->transitions = [
            self::STATE_READY => [
                self::ACTION_BUILD_ENTRY_ZONE => ['to' => self::STATE_ENTRY_ZONE_BUILT],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_ENTRY_ZONE_BUILT => [
                self::ACTION_SUBMIT_MAKER => ['to' => self::STATE_SUBMITTED_MAKER],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_SUBMITTED_MAKER => [
                self::ACTION_WAIT_FILL => ['to' => self::STATE_FILLED], // ou PARTIAL/TIMEOUT
                self::ACTION_CANCEL_MAKER => ['to' => self::STATE_TIMEOUT],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_FILLED => [
                self::ACTION_ATTACH_TP_SL => ['to' => self::STATE_ATTACH_TP_SL],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_PARTIAL => [
                self::ACTION_ATTACH_TP_SL => ['to' => self::STATE_ATTACH_TP_SL],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_TIMEOUT => [
                self::ACTION_SUBMIT_TAKER => ['to' => self::STATE_FILLED],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_ATTACH_TP_SL => [
                self::ACTION_OPEN_POSITION => ['to' => self::STATE_OPENED],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_OPENED => [
                self::ACTION_START_MONITORING => ['to' => self::STATE_MONITORING],
                self::ACTION_FAIL => ['to' => self::STATE_FAILED]
            ],
            self::STATE_MONITORING => [
                // État final
            ],
            self::STATE_FAILED => [
                // État final
            ]
        ];
    }

    /**
     * Obtient l'état actuel
     */
    public function getCurrentState(): string
    {
        return $this->currentState;
    }

    /**
     * Obtient les données d'état
     */
    public function getStateData(): array
    {
        return $this->stateData;
    }
}
