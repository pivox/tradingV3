<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\MtfContractsConfigProvider;
use App\Config\TradeEntryModeContext;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
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

            // Réutilise le parsing de contexte du runner (trim, alias futures/perp,
            // erreur explicite sur valeur inconnue) pour que le preview vise exactement
            // le même exchange/marché qu'un /api/mtf/run avec les mêmes entrées, plutôt
            // que de retomber silencieusement sur Bitmart/perpetual.
            try {
                $runnerRequest = MtfRunnerRequestDto::fromArray([
                    'exchange' => $request->query->get('exchange', $request->query->get('cex')),
                    'market_type' => $request->query->get('market_type', $request->query->get('type_contract')),
                ]);
            } catch (\InvalidArgumentException $e) {
                return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
            }

            $context = ExchangeContext::fromEnums(
                $runnerRequest->exchange ?? Exchange::BITMART,
                $runnerRequest->marketType ?? MarketType::PERPETUAL,
            );

            // On colle strictement à l'univers du runner (findSymbolsMixedLiquidity).
            // Pas d'option ignore_limits : findAllActiveSymbolsWithoutLimits applique
            // le filtre d'âge à l'inverse (open_timestamp > borne) et renverrait un
            // univers incohérent avec ce qu'un run sélectionnerait réellement.
            $symbols = $this->contractRepository->allActiveSymbolNames([], false, $profile, $context);
            $symbols = array_values(array_unique(array_map('strval', $symbols)));

            $config = $this->contractsConfigProvider->getConfigForProfile($profile);
            $filters = [
                'quote_currency' => $config->getFilter('quote_currency', 'USDT'),
                'status' => $config->getFilter('status', 'Trading'),
                'min_turnover' => $config->getFilter('min_turnover', null),
                'mid_max_turnover' => $config->getFilter('mid_max_turnover', null),
                'top_n' => $config->getLimit('top_n', null),
                'mid_n' => $config->getLimit('mid_n', null),
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
