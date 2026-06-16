<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Config\MtfContractsConfigProvider;
use App\Config\TradeEntryModeContext;
use App\Provider\Context\ExchangeContext;
use App\Provider\Repository\ContractRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SF-001 — Expose les contrats réellement sélectionnés par la configuration
 * `mtf_contracts`, en lecture seule.
 *
 * Réutilise exactement le chemin de sélection du runner
 * ({@see ContractRepository::allActiveSymbolNames()}) afin que l'orchestrateur
 * Python et le front récupèrent la même liste de symboles que celle traitée par
 * un run, sans déclencher d'effet de bord (la file MTF switch n'est PAS consommée).
 */
class ContractsApiController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MtfContractsConfigProvider $contractsConfigProvider,
        private readonly TradeEntryModeContext $modeContext,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/mtf/contracts', name: 'api_mtf_contracts', methods: ['GET'])]
    public function contracts(Request $request): JsonResponse
    {
        try {
            $profileInput = $request->query->get('profile', $request->query->get('mtf_profile'));
            $profile = is_string($profileInput) && $profileInput !== '' ? $profileInput : null;

            // Résolution du profil par défaut depuis la config (cf. RunnerController)
            if ($profile === null) {
                $enabledModes = $this->modeContext->getEnabledModes();
                if (!empty($enabledModes)) {
                    $profile = $enabledModes[0]['name'] ?? null;
                }
            }

            $exchangeInput = $request->query->get('exchange', $request->query->get('cex'));
            $marketTypeInput = $request->query->get('market_type', $request->query->get('type_contract'));
            $context = ExchangeContext::fromValues($exchangeInput, $marketTypeInput);

            $ignoreLimits = filter_var($request->query->get('ignore_limits', false), FILTER_VALIDATE_BOOLEAN);

            $symbols = $this->contractRepository->allActiveSymbolNames([], $ignoreLimits, $profile, $context);
            $symbols = array_values(array_unique(array_map('strval', $symbols)));

            $config = $this->contractsConfigProvider->getConfigForProfile($profile);
            $filters = [
                'quote_currency' => $config->getFilter('quote_currency', 'USDT'),
                'status' => $config->getFilter('status', 'Trading'),
                'min_turnover' => $config->getFilter('min_turnover', null),
                'mid_max_turnover' => $config->getFilter('mid_max_turnover', null),
                'top_n' => $config->getLimit('top_n', null),
                'mid_n' => $config->getLimit('mid_n', null),
                'ignore_limits' => $ignoreLimits,
            ];

            return $this->json([
                'ok' => true,
                'profile' => $profile,
                'exchange' => $context->exchange->value,
                'market_type' => $context->marketType->value,
                'count' => count($symbols),
                'symbols' => $symbols,
                'filters' => $filters,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[ContractsApi] Failed to resolve filtered contracts', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
