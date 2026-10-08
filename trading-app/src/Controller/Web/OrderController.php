<?php

namespace App\Controller\Web;

use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Provider\Repository\ContractRepository;
use App\TradeEntry\Pricing\TickQuantizer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class OrderController extends AbstractController
{
    public function __construct(
        private readonly ExchangeExecutionService $execution,
        private readonly ContractRepository $contractRepository,
        #[Autowire(service: 'monolog.logger.provider')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/order/submit', name: 'order_submit')]
    public function submit(Request $request): Response
    {
        $contracts = $this->contractRepository->findActiveContracts();
        $contractSymbols = [];
        foreach ($contracts as $contract) {
            $contractSymbols[] = $contract->getSymbol();
        }

        $orderId = null;
        $error = null;
        $calculatedSize = null;
        $orderDetails = null;

        if ($request->isMethod('POST')) {
            $symbol = $request->request->get('symbol');
            $initialMargin = $request->request->get('initial_margin');
            $riskPercent = $request->request->get('risk_percent');
            $takeProfitPrice = $request->request->get('take_profit_price');
            $leverage = $request->request->get('leverage');
            $side = $request->request->get('side');
            $limitPrice = $request->request->get('limit_price');

            // Validation
            if (empty($symbol) || empty($initialMargin) || empty($riskPercent) || 
                empty($takeProfitPrice) || empty($leverage) || empty($side) || empty($limitPrice)) {
                $error = 'Tous les champs doivent être remplis.';
            } elseif (!is_numeric($initialMargin) || (float) $initialMargin <= 0) {
                $error = 'La marge initiale doit être un nombre positif.';
            } elseif (!is_numeric($riskPercent) || (float) $riskPercent <= 0 || (float) $riskPercent > 100) {
                $error = 'Le pourcentage de risque doit être entre 0 et 100.';
            } elseif (!is_numeric($takeProfitPrice) || (float) $takeProfitPrice <= 0) {
                $error = 'Le prix TP doit être un nombre positif.';
            } elseif (!is_numeric($leverage) || (float) $leverage <= 0) {
                $error = 'Le levier doit être un nombre positif.';
            } elseif (!in_array($side, ['1', '4'], true)) {
                $error = 'La direction doit être Achat (1) ou Vente (4).';
            } elseif (!is_numeric($limitPrice) || (float) $limitPrice <= 0) {
                $error = 'Le prix limit doit être un nombre positif.';
            } else {
                try {
                    // Récupérer le contrat pour obtenir le contract_size
                    $contract = $this->contractRepository->findBySymbol(strtoupper($symbol));
                    if (!$contract) {
                        $error = 'Contrat non trouvé pour le symbole: ' . $symbol;
                    } else {
                        $contractSize = $contract->getContractSize();
                        if ($contractSize === null || (float) $contractSize <= 0) {
                            $error = 'Le contrat ne contient pas de contract_size valide. Veuillez synchroniser les contrats.';
                        } else {
                            $contractSizeFloat = (float) $contractSize;
                            
                            // Récupérer la précision du prix depuis le contrat
                            $pricePrecisionStr = $contract->getPricePrecision();
                            $pricePrecision = $this->resolvePricePrecision($pricePrecisionStr);
                            
                            // Calculer la taille de l'ordre basée sur la marge et le levier
                            // Valeur notionale = Marge initiale * Levier
                            $notionalValue = (float) $initialMargin * (float) $leverage;
                            
                            // Taille en contrats = Valeur notionale / (Prix limit * Contract Size)
                            // Pour les contrats futures: Notional = Prix × Contract Size × Nombre de contrats
                            $calculatedSize = floor($notionalValue / ((float) $limitPrice * $contractSizeFloat));
                            
                            if ($calculatedSize <= 0) {
                                $error = sprintf(
                                    'La taille calculée est invalide (%.2f contrats). Vérifiez la marge initiale (%.2f), le levier (%s) et le prix limit (%.2f). Contract size: %s',
                                    $calculatedSize,
                                    (float) $initialMargin,
                                    $leverage,
                                    (float) $limitPrice,
                                    $contractSize
                                );
                            } else {
                                // Calculer le Stop Loss à partir de limit_price, risk_percent et leverage
                                // riskUsdt = initialMargin * (riskPercent / 100)
                                $riskUsdt = ((float) $initialMargin * (float) $riskPercent) / 100.0;
                                
                                // Distance du SL: dMax = riskUsdt / (contractSize * size)
                                // Pour Long (side=1): SL = limitPrice - dMax
                                // Pour Short (side=4): SL = limitPrice + dMax
                                $dMax = $riskUsdt / max($contractSizeFloat * $calculatedSize, 1e-12);
                                $limitPriceFloat = (float) $limitPrice;
                                
                                if ((int) $side === 1) {
                                    // Long: SL en dessous du prix limite
                                    $stopLossPrice = $limitPriceFloat - $dMax;
                                } else {
                                    // Short: SL au-dessus du prix limite
                                    $stopLossPrice = $limitPriceFloat + $dMax;
                                }
                                
                                // Quantifier le prix du SL selon la précision
                                if ((int) $side === 1) {
                                    // Long: quantize up (closer to entry to maintain risk constraint)
                                    $stopLossPrice = TickQuantizer::quantizeUp($stopLossPrice, $pricePrecision);
                                } else {
                                    // Short: quantize down (closer to entry to maintain risk constraint)
                                    $stopLossPrice = TickQuantizer::quantize($stopLossPrice, $pricePrecision);
                                }
                                
                                // Validation du SL
                                if ($stopLossPrice <= 0) {
                                    $error = sprintf(
                                        'Le Stop Loss calculé est invalide (%.8f). Vérifiez les paramètres de risque.',
                                        $stopLossPrice
                                    );
                                } elseif ((int) $side === 1 && $stopLossPrice >= $limitPriceFloat) {
                                    $error = sprintf(
                                        'Le Stop Loss pour un ordre Long doit être inférieur au prix limite (SL: %.8f >= Limit: %.8f).',
                                        $stopLossPrice,
                                        $limitPriceFloat
                                    );
                                } elseif ((int) $side === 4 && $stopLossPrice <= $limitPriceFloat) {
                                    $error = sprintf(
                                        'Le Stop Loss pour un ordre Short doit être supérieur au prix limite (SL: %.8f <= Limit: %.8f).',
                                        $stopLossPrice,
                                        $limitPriceFloat
                                    );
                                } else {
                                    // IMPORTANT: Le levier doit être défini AVANT de soumettre l'ordre
                                    try {
                                        $execution = $this->execution->execute(
                                            new OrderPlanModel(
                                                symbol: strtoupper($symbol),
                                                side: (int) $side === 1 ? Side::Long : Side::Short,
                                                orderType: 'limit',
                                                openType: 'isolated',
                                                orderMode: 1,
                                                entry: $limitPriceFloat,
                                                stop: $stopLossPrice,
                                                takeProfit: (float) $takeProfitPrice,
                                                size: (int) $calculatedSize,
                                                leverage: (int) $leverage,
                                                pricePrecision: $pricePrecision,
                                                contractSize: $contractSizeFloat,
                                                exchangeContext: ExchangeContext::resolve(null),
                                            ),
                                            'manual-' . bin2hex(random_bytes(6)),
                                        );
                                        $leverageSuccess = ($execution->raw['leverage_submit_success'] ?? true) !== false;

                                        if (!$leverageSuccess) {
                                            $error = 'Échec de la définition du levier. Veuillez réessayer.';
                                            $this->logger->error('[Order Submit] Leverage submission failed', [
                                                'symbol' => $symbol,
                                                'leverage' => $leverage,
                                            ]);
                                        } else {
                                            $this->logger->info('[Order Submit] Leverage set successfully', [
                                                'symbol' => $symbol,
                                                'leverage' => $leverage,
                                            ]);
                                            
                                            if ($execution->exchangeOrderId !== null && !\in_array($execution->status, [ExecutionResult::STATUS_ERROR, ExecutionResult::STATUS_SKIPPED], true)) {
                                                $orderId = $execution->exchangeOrderId;
                                                
                                                // Stocker tous les détails de l'ordre pour l'affichage
                                                $orderDetails = [
                                                    'order_id' => $orderId,
                                                    'symbol' => strtoupper($symbol),
                                                    'side' => (int) $side,
                                                    'side_label' => $side === '1' ? 'Achat (Open Long)' : 'Vente (Open Short)',
                                                    'type' => 'limit',
                                                    'size' => $calculatedSize,
                                                    'limit_price' => (float) $limitPrice,
                                                    'stop_loss_price' => $stopLossPrice,
                                                    'take_profit_price' => (float) $takeProfitPrice,
                                                    'initial_margin' => (float) $initialMargin,
                                                    'risk_percent' => (float) $riskPercent,
                                                    'leverage' => (int) $leverage,
                                                    'contract_size' => (float) $contractSize,
                                                    'price_precision' => $pricePrecision,
                                                    'notional_value' => $notionalValue,
                                                    'risk_amount' => $riskUsdt,
                                                    'sl_distance' => $dMax,
                                                    // Données soumises (request)
                                                    'request_payload' => [
                                                        'symbol' => strtoupper($symbol),
                                                        'side' => (int) $side,
                                                        'type' => 'limit',
                                                        'price' => (string) $limitPrice,
                                                        'size' => (int) $calculatedSize,
                                                        'open_type' => 'isolated',
                                                        'preset_take_profit_price' => (string) $takeProfitPrice,
                                                        'preset_take_profit_price_type' => 1,
                                                        'preset_stop_loss_price' => (string) $stopLossPrice,
                                                        'preset_stop_loss_price_type' => 1,
                                                    ],
                                                    'leverage_request' => [
                                                        'symbol' => strtoupper($symbol),
                                                        'leverage' => (int) $leverage,
                                                        'open_type' => 'isolated',
                                                    ],
                                                    'leverage_response' => [
                                                        'code' => 1000,
                                                        'message' => 'success',
                                                        'data' => ['leverage' => (string) $leverage],
                                                    ],
                                                    'response_full' => [
                                                        'code' => 1000,
                                                        'message' => 'success',
                                                        'data' => [
                                                            'order_id' => $orderId,
                                                            'symbol' => strtoupper($symbol),
                                                            'side' => (int) $side,
                                                            'type' => 'limit',
                                                            'price' => (string) $limitPrice,
                                                            'size' => (int) $calculatedSize,
                                                        ],
                                                    ],
                                                ];
                                        
                                                $this->logger->info('[Order Submit] Order submitted successfully', [
                                                    'order_id' => $orderId,
                                                    'symbol' => $symbol,
                                                    'side' => $side,
                                                    'calculated_size' => $calculatedSize,
                                                    'limit_price' => $limitPrice,
                                                    'stop_loss_price' => $stopLossPrice,
                                                    'take_profit_price' => $takeProfitPrice,
                                                    'leverage' => $leverage,
                                                    'contract_size' => $contractSize,
                                                    'notional_value' => $notionalValue,
                                                    'risk_usdt' => $riskUsdt,
                                                ]);
                                            } else {
                                                $error = 'Ordre soumis mais aucun order_id reçu dans la réponse.';
                                                $this->logger->warning('[Order Submit] No order_id in response', [
                                                    'execution' => $execution->raw,
                                                ]);
                                            }
                                        }
                                    } catch (\Throwable $leverageException) {
                                        $error = sprintf('Erreur lors de la soumission de l\'ordre: %s', $leverageException->getMessage());
                                        $this->logger->error('[Order Submit] Order submission exception', [
                                            'error' => $leverageException->getMessage(),
                                            'trace' => $leverageException->getTraceAsString(),
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $error = sprintf('Erreur lors de la soumission: %s', $e->getMessage());
                    $this->logger->error('[Order Submit] Exception', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        return $this->render('TradeEntry/submit.html.twig', [
            'contracts' => $contractSymbols,
            'order_id' => $orderId,
            'error' => $error,
            'calculated_size' => $calculatedSize,
            'order_details' => $orderDetails,
        ]);
    }

    /**
     * Résout la précision du prix depuis une chaîne (peut être un entier ou un tick size comme 0.1, 0.01, etc.)
     */
    private function resolvePricePrecision(?string $pricePrecision): int
    {
        if ($pricePrecision === null || $pricePrecision === '') {
            return 2; // Par défaut: 2 décimales
        }

        // Case 1: déjà un entier (nombre de décimales)
        if (preg_match('/^\d+$/', $pricePrecision)) {
            return (int) $pricePrecision;
        }

        // Case 2: provider retourne un tick size comme 0.1 / 0.01 / 0.0001...
        $dotPos = strpos($pricePrecision, '.');
        if ($dotPos === false) {
            // Fallback: arrondir au plus proche entier
            return max(0, min(10, (int) round((float) $pricePrecision)));
        }

        // Compter les chiffres après la virgule, en supprimant les zéros de fin
        $frac = rtrim(substr($pricePrecision, $dotPos + 1), '0');
        $decimals = strlen($frac);

        // Si la valeur est exactement une puissance de 10 (ex: 0.01), c'est correct.
        // Sinon, garder une limite raisonnable.
        return max(0, min(10, $decimals));
    }
}
