<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserExchangeAccount;
use App\Repository\UserExchangeAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/user-exchange-accounts', name: 'api_user_exchange_accounts_')]
final class UserExchangeAccountController extends AbstractController
{
    public function __construct(
        private readonly UserExchangeAccountRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $accounts = $this->repository->findAll();
        $payload = array_map(fn (UserExchangeAccount $account) => $this->serialize($account), $accounts);

        return new JsonResponse($payload);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->decodeRequest($request);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        $userId = trim((string)($data['user_id'] ?? $data['userId'] ?? ''));
        $exchange = trim((string)($data['exchange'] ?? ''));

        if ($userId === '' || $exchange === '') {
            return $this->json(['error' => 'user_id and exchange are required'], 400);
        }

        if ($this->repository->findOneByUserAndExchange($userId, $exchange) instanceof UserExchangeAccount) {
            return $this->json(['error' => 'Account already exists for this user/exchange'], 409);
        }

        $account = (new UserExchangeAccount())
            ->setUserId($userId)
            ->setExchange($exchange);

        $this->hydrateBalances($account, $data);
        if (($error = $this->hydrateSyncDates($account, $data)) !== null) {
            return $this->json(['error' => $error], 400);
        }

        $this->em->persist($account);
        $this->em->flush();

        return $this->json($this->serialize($account), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $account = $this->repository->find($id);
        if (!$account instanceof UserExchangeAccount) {
            return $this->json(['error' => 'Account not found'], 404);
        }

        $data = $this->decodeRequest($request);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        if (isset($data['user_id']) || isset($data['userId'])) {
            $userId = trim((string)($data['user_id'] ?? $data['userId']));
            if ($userId === '') {
                return $this->json(['error' => 'user_id cannot be empty'], 400);
            }
            $account->setUserId($userId);
        }

        if (isset($data['exchange'])) {
            $exchange = trim((string) $data['exchange']);
            if ($exchange === '') {
                return $this->json(['error' => 'exchange cannot be empty'], 400);
            }
            $account->setExchange($exchange);
        }

        $this->hydrateBalances($account, $data);
        if (($error = $this->hydrateSyncDates($account, $data, false)) !== null) {
            return $this->json(['error' => $error], 400);
        }

        $duplicate = $this->repository->findOneByUserAndExchange($account->getUserId(), $account->getExchange());
        if ($duplicate instanceof UserExchangeAccount && $duplicate->getId() !== $account->getId()) {
            return $this->json(['error' => 'Another account already exists for this user/exchange'], 409);
        }

        $this->em->flush();

        return $this->json($this->serialize($account));
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

    private function hydrateBalances(UserExchangeAccount $account, array $data): void
    {
        $mapping = [
            'available_balance' => 'setAvailableBalance',
            'availableBalance' => 'setAvailableBalance',
            'balance' => 'setBalance',
        ];

        foreach ($mapping as $key => $setter) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null || $value === '') {
                $account->{$setter}(null);
                continue;
            }

            $account->{$setter}((float) $value);
        }
    }

    private function hydrateSyncDates(UserExchangeAccount $account, array $data, bool $forceNowIfMissing = true): ?string
    {
        $balanceProvided = array_key_exists('last_balance_sync_at', $data) || array_key_exists('lastBalanceSyncAt', $data);
        $orderProvided = array_key_exists('last_order_sync_at', $data) || array_key_exists('lastOrderSyncAt', $data);

        if ($balanceProvided) {
            $value = array_key_exists('last_balance_sync_at', $data) ? $data['last_balance_sync_at'] : $data['lastBalanceSyncAt'];
            if ($value === null || $value === '') {
                $account->setLastBalanceSyncAt(null);
            } else {
                $parsed = $this->tryParseDateTime($value);
                if ($parsed === false) {
                    return 'Invalid last_balance_sync_at format';
                }
                $account->setLastBalanceSyncAt($parsed);
            }
        } elseif ($forceNowIfMissing && $account->getLastBalanceSyncAt() === null) {
            $account->setLastBalanceSyncAt(new \DateTimeImmutable());
        }

        if ($orderProvided) {
            $value = array_key_exists('last_order_sync_at', $data) ? $data['last_order_sync_at'] : $data['lastOrderSyncAt'];
            if ($value === null || $value === '') {
                $account->setLastOrderSyncAt(null);
            } else {
                $parsed = $this->tryParseDateTime($value);
                if ($parsed === false) {
                    return 'Invalid last_order_sync_at format';
                }
                $account->setLastOrderSyncAt($parsed);
            }
        } elseif ($forceNowIfMissing && $account->getLastOrderSyncAt() === null) {
            $account->setLastOrderSyncAt(new \DateTimeImmutable());
        }

        return null;
    }

    private function tryParseDateTime(mixed $value): \DateTimeImmutable|false
    {
        if ($value === null || $value === '') {
            return false;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return false;
        }
    }

    private function serialize(UserExchangeAccount $account): array
    {
        return [
            'id' => $account->getId(),
            'user_id' => $account->getUserId(),
            'exchange' => $account->getExchange(),
            'available_balance' => $account->getAvailableBalance(),
            'balance' => $account->getBalance(),
            'last_balance_sync_at' => $account->getLastBalanceSyncAt()?->format(DATE_ATOM),
            'last_order_sync_at' => $account->getLastOrderSyncAt()?->format(DATE_ATOM),
            'created_at' => $account->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $account->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
