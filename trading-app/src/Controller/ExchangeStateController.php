<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Runner\OpenStateSnapshotSerializer;
use App\Common\Enum\Exchange;
use App\Contract\Provider\MainProviderInterface;
use App\Provider\Context\ExchangeContextResolver;
use App\Runtime\Safety\FakeOnlyExchangeCallAudit;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SF-002b — endpoint en lecture seule produisant l'instantané d'état ouvert
 * (positions/ordres) que l'orchestrateur récupère UNE seule fois puis transmet
 * à chaque appel `/api/mtf/run` (open_state_snapshot), évitant un fetch exchange
 * par set.
 */
final class ExchangeStateController extends AbstractController
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly ExchangeContextResolver $contextResolver,
        private readonly OpenStateSnapshotSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly FakeOnlyExchangeCallAudit $fakeOnlyExchangeCallAudit = new FakeOnlyExchangeCallAudit(),
    ) {
    }

    #[Route('/api/exchange/open-state', name: 'api_exchange_open_state', methods: ['GET'])]
    public function openState(Request $request): JsonResponse
    {
        try {
            $context = $this->contextResolver->resolve($request->query->all());
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $safetyEvidenceRequested = $request->headers->get('X-Fake-Only-Safety-Evidence') === 'v1';
        if ($safetyEvidenceRequested) {
            $explicitDryRun = filter_var(
                $request->query->get('dry_run'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            if ($context->exchange !== Exchange::FAKE || $explicitDryRun !== true) {
                return $this->json([
                    'status' => 'error',
                    'error_code' => 'fake_only_safety_context_invalid',
                    'message' => 'Fake-only safety evidence requires exchange=fake and dry_run=true.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->fakeOnlyExchangeCallAudit->begin(asyncExchangeCapableDispatchesSuppressed: true);
        }

        try {
            $provider = $this->mainProvider->forContext($context);
            $accountProvider = $provider->getAccountProvider();
            $orderProvider = $provider->getOrderProvider();

            // Variantes "OrFail" : une panne/erreur provider lève (au lieu de []),
            // ce qui produit une réponse non-200 (cf. catch ci-dessous). L'orchestrateur
            // fail-close alors les sets live au lieu de trader sur un snapshot vide trompeur.
            $openPositions = $accountProvider !== null ? $accountProvider->getOpenPositionsOrFail() : [];
            $openOrders = $orderProvider !== null ? $orderProvider->getOpenOrdersOrFail() : [];

            $snapshot = $this->serializer->serialize($openPositions, $openOrders);
            if ($safetyEvidenceRequested) {
                $snapshot['fake_only_safety_evidence'] = $this->fakeOnlyExchangeCallAudit->finish();
            }

            $this->logger->info('[Exchange State] Open-state snapshot produced', [
                'exchange' => $context->exchange->value,
                'market_type' => $context->marketType->value,
                'positions_count' => count($snapshot['open_positions']),
                'orders_count' => count($snapshot['open_orders']),
            ]);

            return $this->json($snapshot);
        } catch (\Throwable $e) {
            $this->logger->error('[Exchange State] Failed to produce open-state snapshot', [
                'error' => $e->getMessage(),
            ]);

            if ($this->fakeOnlyExchangeCallAudit->isActive()) {
                $this->fakeOnlyExchangeCallAudit->recordAmbiguousAttempt();

                return $this->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'fake_only_safety_evidence' => $this->fakeOnlyExchangeCallAudit->finish(),
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            // 503 : panne/erreur exchange transitoire. Le client orchestrateur traite
            // tout non-200 comme open-state indisponible (fail-closed des sets live).
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
