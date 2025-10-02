<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TradingConfiguration;
use App\Repository\TradingConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/trading-configurations', name: 'api_trading_configurations_')]
final class TradingConfigurationController extends AbstractController
{
    public function __construct(
        private readonly TradingConfigurationRepository $repository,
        private readonly EntityManagerInterface $em
    ) {}

    private const ALLOWED_CONTEXTS = [
        TradingConfiguration::CONTEXT_GLOBAL,
        TradingConfiguration::CONTEXT_STRATEGY,
        TradingConfiguration::CONTEXT_EXECUTION,
        TradingConfiguration::CONTEXT_SECURITY,
    ];

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $configs = $this->repository->findAll();

        $account = [
            'id' => null,
            'context' => TradingConfiguration::CONTEXT_GLOBAL,
            'scope' => null,
            'budget_cap_usdt' => null,
            'risk_abs_usdt' => null,
            'tp_abs_usdt' => null,
            'updated_at' => null,
        ];

        $items = [];

        foreach ($configs as $config) {
            if ($config->getContext() === TradingConfiguration::CONTEXT_GLOBAL && $config->getScope() === null) {
                $account = [
                    'id' => $config->getId(),
                    'context' => $config->getContext(),
                    'scope' => $config->getScope(),
                    'budget_cap_usdt' => $config->getBudgetCapUsdt(),
                    'risk_abs_usdt' => $config->getRiskAbsUsdt(),
                    'tp_abs_usdt' => $config->getTpAbsUsdt(),
                    'updated_at' => $config->getUpdatedAt()->format(DATE_ATOM),
                ];
                continue;
            }

            $items[] = $this->serialize($config);
        }

        return $this->json([
            'account' => $account,
            'items' => $items,
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->decodeRequest($request);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        $context = strtolower((string)($data['context'] ?? ''));
        if ($context === '') {
            return $this->json(['error' => 'context is required'], 400);
        }

        if (!\in_array($context, self::ALLOWED_CONTEXTS, true)) {
            return $this->json(['error' => sprintf('Unknown context "%s"', $context)], 400);
        }

        if ($context === TradingConfiguration::CONTEXT_GLOBAL && $this->repository->findGlobal() instanceof TradingConfiguration) {
            return $this->json(['error' => 'Global account configuration already exists, use update endpoint'], 409);
        }

        $config = (new TradingConfiguration())
            ->setContext($context)
            ->setScope($context === TradingConfiguration::CONTEXT_GLOBAL ? null : $this->extractNullableString($data, 'scope'));

        $allowNumericValues = $context === TradingConfiguration::CONTEXT_GLOBAL;
        if (($error = $this->hydrateNumericFields($config, $data, true, $allowNumericValues)) !== null) {
            return $this->json(['error' => $error], 400);
        }
        $this->hydrateBannedContracts($config, $data);

        $this->em->persist($config);
        $this->em->flush();

        return $this->json($this->serialize($config), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $config = $this->repository->find($id);
        if (!$config instanceof TradingConfiguration) {
            return $this->json(['error' => 'Trading configuration not found'], 404);
        }

        $data = $this->decodeRequest($request);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        if (isset($data['context'])) {
            $context = strtolower((string) $data['context']);
            if (!\in_array($context, self::ALLOWED_CONTEXTS, true)) {
                return $this->json(['error' => sprintf('Unknown context "%s"', $context)], 400);
            }
            if ($config->getContext() === TradingConfiguration::CONTEXT_GLOBAL && $context !== TradingConfiguration::CONTEXT_GLOBAL) {
                return $this->json(['error' => 'Cannot change global configuration context'], 400);
            }
            if ($config->getContext() !== TradingConfiguration::CONTEXT_GLOBAL && $context === TradingConfiguration::CONTEXT_GLOBAL) {
                if (($existing = $this->repository->findGlobal()) instanceof TradingConfiguration && $existing->getId() !== $config->getId()) {
                    return $this->json(['error' => 'Global configuration already exists'], 409);
                }
            }
            $config->setContext($context);
        }

        if ($config->getContext() === TradingConfiguration::CONTEXT_GLOBAL) {
            $config->setScope(null);
        } elseif (array_key_exists('scope', $data)) {
            $config->setScope($this->extractNullableString($data, 'scope'));
        }

        $allowNumericValues = $config->getContext() === TradingConfiguration::CONTEXT_GLOBAL;
        if (($error = $this->hydrateNumericFields($config, $data, true, $allowNumericValues)) !== null) {
            return $this->json(['error' => $error], 400);
        }
        $this->hydrateBannedContracts($config, $data, false);

        $this->em->flush();

        return $this->json($this->serialize($config));
    }

    private function decodeRequest(Request $request): mixed
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function extractNullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if ($value === null || $value === '') {
            return null;
        }

        return \is_string($value) ? trim($value) : (string) $value;
    }

    private function hydrateNumericFields(TradingConfiguration $config, array $data, bool $overwriteNull = true, bool $allowValues = true): ?string
    {
        $map = [
            'budget_cap_usdt' => 'setBudgetCapUsdt',
            'budgetCapUsdt' => 'setBudgetCapUsdt',
            'risk_abs_usdt' => 'setRiskAbsUsdt',
            'riskAbsUsdt' => 'setRiskAbsUsdt',
            'tp_abs_usdt' => 'setTpAbsUsdt',
            'tpAbsUsdt' => 'setTpAbsUsdt',
        ];

        if (!$allowValues && $overwriteNull) {
            $config->setBudgetCapUsdt(null);
            $config->setRiskAbsUsdt(null);
            $config->setTpAbsUsdt(null);
        }

        foreach ($map as $key => $setter) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null || $value === '') {
                if ($overwriteNull) {
                    $config->{$setter}(null);
                }
                continue;
            }

            if (!$allowValues) {
                return sprintf('%s is only configurable at the account level', $key);
            }

            if (!is_numeric($value)) {
                return sprintf('%s must be numeric', $key);
            }

            $config->{$setter}((float) $value);
        }

        return null;
    }

    private function hydrateBannedContracts(TradingConfiguration $config, array $data, bool $clearIfMissing = true): void
    {
        $keys = ['banned_contracts', 'bannedContracts'];
        $valueProvided = false;

        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $valueProvided = true;
            $raw = $data[$key];

            if ($raw === null || $raw === '') {
                $config->setBannedContracts(null);
                continue;
            }

            if (\is_string($raw)) {
                $config->setBannedContracts($raw);
                continue;
            }

            if (is_array($raw)) {
                $config->setBannedContractsFromArray($raw);
            }
        }

        if (!$valueProvided && $clearIfMissing === false) {
            return;
        }

        if (!$valueProvided && $clearIfMissing === true) {
            $config->setBannedContracts(null);
        }
    }

    private function serialize(TradingConfiguration $config): array
    {
        return [
            'id' => $config->getId(),
            'context' => $config->getContext(),
            'scope' => $config->getScope(),
            'budget_cap_usdt' => $config->getBudgetCapUsdt(),
            'risk_abs_usdt' => $config->getRiskAbsUsdt(),
            'tp_abs_usdt' => $config->getTpAbsUsdt(),
            'banned_contracts' => $config->getBannedContracts(),
            'created_at' => $config->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $config->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
