<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Service\Trading\AssetsDetails;
use App\Repository\{ContractRepository, ContractPipelineRepository, RuntimeGuardRepository};
use App\Service\Config\TradingParameters;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class BitmartCronController extends AbstractController
{
    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
        private readonly RuntimeGuardRepository $runtimeGuardRepository,
        private readonly ContractPipelineRepository $contractPipelineRepository,
        private readonly AssetsDetails $assetsDetails
    ) {}

    private const BASE_URL = 'http://nginx';
    private const CALLBACK = 'api/callback/bitmart/get-kline';
    private const LIMIT_KLINES = 260;

    #[Route('/api/cron/bitmart/refresh-{tf}', name: 'bitmart_cron_refresh', methods: ['POST'])]
    public function refresh(
        string $tf,
        LoggerInterface $logger,
        Request $request,
        TradingParameters $tradingParams
    ): JsonResponse {
        if ($guard = $this->guard()) {
            return $guard;
        }

        // Ordre de cascade du plus grand au plus petit timeframe
        $cascadeOrder = ['4h', '1h', '15m', '5m', '1m'];
        if (!in_array($tf, $cascadeOrder, true)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Timeframe invalide',
                'allowed' => $cascadeOrder,
            ], 400);
        }

        // --- CONTRÔLE SOLDE POUR 1m ---
        if ($tf === '1m') {
            try {
                if (!$this->assetsDetails->hasEnoughtMoneyInUsdt('USDT', 50)) {
                    return $this->json([
                        'status' => 'skipped_assets_insufficient',
                        'timeframe' => $tf,
                    ], 200);
                }
            } catch (\Throwable $e) {
                $logger->warning('Cron 1m: exception sur assets-detail, on annule l\'envoi', [
                    'error' => $e->getMessage()
                ]);
                return $this->json([
                    'status' => 'skipped_assets_exception',
                    'timeframe' => $tf,
                    'error' => $e->getMessage()
                ], 200);
            }
        }
        // --- FIN CONTRÔLE SOLDE ---
        $symbols = [];

        if ($request->query->has('symbol')) {
            $symbol = (string)$request->query->get('symbol');
            $symbol = $this->contractRepository->find($symbol);
            if ($symbol) {
                $symbols = [$symbol];
            }
        } elseif ($tf === '4h') {
            $symbols = $this->contractRepository->allActiveSymbols();
        } elseif (in_array($tf, ['1m','5m','15m'], true)) {
            // Pour ces timeframes on prend tous les pipelines actifs dont currentTimeframe ∈ {1m,5m,15m}
            $collected = [];
            foreach (['1m','5m','15m'] as $grpTf) {
                foreach ($this->contractPipelineRepository->getAllSymbolsWithActiveTimeframe($grpTf) as $c) {
                    $collected[$c->getSymbol()] = $c; // déduplication par symbol
                }
            }
            $symbols = array_values($collected);
            // Filtre additionnel: isValid4h & isValid1h doivent être true et aucune validité explicite false sur autres TF
            $symbols = array_filter($symbols, function($contract) {
                $p = $contract->getContractPipeline();
                if (!$p) return false;
                if ($p->isValid4h() !== true || $p->isValid1h() !== true) return false;
                // si d'autres flags sont explicitement false, on exclut (null accepté)
                $others = [$p->isValid15m(), $p->isValid5m(), $p->isValid1m()];
                foreach ($others as $flag) {
                    if ($flag === false) return false;
                }
                return true;
            });
        } else {
            // cas '1h' (et fallback éventuel)
            $symbols = $this->contractPipelineRepository->getAllSymbolsWithActiveTimeframe($tf);
        }
       // $symbols = $this->removeFreshSymbols($symbols, $tf);

        $batchId     = sprintf('cron-%s-%s', strtolower($tf), (new \DateTimeImmutable())->format('YmdHisv'));
        $workflowRef = new WorkflowRef('api-rate-limiter-workflow', 'ApiRateLimiterClient', 'api_rate_limiter_queue');

        // Liste des timeframes parents à cascader si stales
        $listCascades = $this->getListCascades($symbols, $tf);

        $this->bitmartOrchestrator->reset();
        $this->bitmartOrchestrator->setWorkflowRef($workflowRef);
        $count = 0;

        // Envois cascade (timeframes supérieurs). Toujours meta DONT_INC_DEC_DEL
        foreach ($listCascades as $parentTf => $tfSymbols) {
            foreach ($tfSymbols as $symbol) {
                $this->bitmartOrchestrator->requestGetKlines(
                    $workflowRef,
                    baseUrl: self::BASE_URL,
                    callback: self::CALLBACK,
                    contract: $symbol->getSymbol(),
                    timeframe: $parentTf,
                    limit: self::LIMIT_KLINES,
                    note: "cron $tf (cascade $parentTf)",
                    batchId: $batchId,
                    meta: [
                        'pipeline' => ContractPipeline::DONT_INC_DEC_DEL,
                    ]
                );
                $count++;
            }
        }

        // Envoi pour le timeframe courant : toujours envoyer; meta conditionnel
        foreach ($symbols as $symbol) {
            $pipeline = $symbol->getContractPipeline();
            $sendMeta = false;
            if ($tf === '5m') {
                $notValid15m = !$pipeline || $pipeline->isValidTf('15m') !== true;
                if ($notValid15m) { $sendMeta = true; }
            } elseif ($tf === '1m') {
                $notValid5m  = !$pipeline || $pipeline->isValidTf('5m') !== true;
                $notValid15m = !$pipeline || $pipeline->isValidTf('15m') !== true;
                if ($notValid5m || $notValid15m) { $sendMeta = true; }
            } elseif ($tf === '15m') {
                $sendMeta = false;
            }

            $args = [
                $workflowRef,
                'baseUrl' => self::BASE_URL,
                'callback' => self::CALLBACK,
                'contract' => $symbol->getSymbol(),
                'timeframe' => $tf,
                'limit' => self::LIMIT_KLINES,
                'note' => "cron $tf",
                'batchId' => $batchId,
            ];
            if ($sendMeta) {
                $args['meta'] = ['pipeline' => ContractPipeline::DONT_INC_DEC_DEL];
            }
            $this->bitmartOrchestrator->requestGetKlines(...$args);
            $count++;
        }

        $this->bitmartOrchestrator->go();

        return $this->json([
            'status' => 'ok',
            'timeframe' => $tf,
            'sent' => count($symbols),
            'requests_total' => $count,
            'batch_id' => $batchId,
            'list' => array_map(
                static fn(Contract $c) => $c->getSymbol(),
                $symbols
            ),
        ]);
    }


    private function guard(): ?JsonResponse {
        return $this->runtimeGuardRepository->isPaused()
            ? new JsonResponse(['status' => 'paused'], 200)
            : null;
    }

    private function removeFreshSymbols(array $symbols, string $tf): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($tf === '4h') {
            /** @var Contract $contract */
            $symbols = array_filter($symbols, function ($contract) use ($now) {
                return is_null($contract->getLastAttemptedAt()) ||
                    $contract->getLastAttemptedAt() <= $now->modify('-4 hours');
            });
        } else {
            $symbols = array_filter($symbols, function ($contract) use ($now, $tf) {
                $intervals = ['4h' => 120, '1h' => 30, '15m' => 10, '5m' => 3, '1m' => 1]; // ajout 1m
                $pipeline = $contract->getContractPipeline();
                if (!$pipeline) {
                    return true; // si pas de pipeline on traite
                }
                $last = $pipeline->getLastAttemptedAtTimeframe($tf);
                return is_null($last) || $last <= $now->modify('-' . $intervals[$tf] . ' minutes');
            });
        }

        return $symbols;
    }

    private function getListCascades(array $symbols, string $currentTf): array
    {
        // timeframes supérieurs potentiels (on exclut le timeframe courant et ceux en dessous)
        $ordered = ['4h', '1h', '15m', '5m', '1m'];
        $intervals = ['4h' => 240, '1h' => 60, '15m' => 15, '5m' => 5, '1m' => 1];
        $currentIndex = array_search($currentTf, $ordered, true);
        if ($currentIndex === false) {
            return [];
        }
        $parents = array_slice($ordered, 0, $currentIndex); // timeframes plus grands
        if (empty($parents)) {
            return [];
        }
        $listCascades = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        /** @var Contract $symbol */
        foreach ($symbols as $symbol) {
            $pipeline = $symbol->getContractPipeline();
            foreach ($parents as $parentTf) {
                // Récupération du lastAttempted selon TF
                if ($parentTf === '4h') {
                    $lastAttempted = $symbol->getLastAttemptedAt();
                } else {
                    $lastAttempted = $pipeline?->getLastAttemptedAtTimeframe($parentTf);
                }
                $threshold = $now->modify('-' . $intervals[$parentTf] . ' minutes');
                $stale = $lastAttempted === null || $lastAttempted <= $threshold;
                if ($stale) {
                    $listCascades[$parentTf][] = $symbol;
                }
            }
        }
        // Assure que toutes les clés existent même si vides (optionnel)
        foreach ($parents as $p) {
            $listCascades[$p] = $listCascades[$p] ?? [];
        }
        return $listCascades;
    }
}
