<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\UserExchangeAccount;
use App\Repository\UserExchangeAccountRepository;
use App\Service\Bitmart\Private\AssetsService;
use DateTimeImmutable;

final class BitmartAccountService implements BitmartAccountGateway
{
    private ?array $usdtAsset = null;
    private ?array $assetsCache = null;

    public function __construct(
        private readonly AssetsService $assets,
        private readonly UserExchangeAccountRepository $accounts,
        private readonly string $defaultUserId,
        private readonly string $exchangeName
    ) {}

    public function getEquity(): float
    {
        $asset = $this->getUsdtAsset();
        return (float)($asset['equity'] ?? $asset['available_balance'] ?? 0.0);
    }

    public function getAvailableUSDT(): float
    {
        $asset = $this->getUsdtAsset();
        return (float)($asset['available_balance'] ?? 0.0);
    }

    private function getUsdtAsset(): array
    {
        if ($this->usdtAsset !== null) {
            return $this->usdtAsset;
        }

        $asset = [];
        foreach ($this->fetchAssets() as $row) {
            if (isset($row['currency']) && strtoupper((string)$row['currency']) === 'USDT') {
                $asset = $row;
                break;
            }
        }

        $this->usdtAsset = $asset;
        $this->persistAccountSnapshot($asset);

        return $asset;
    }

    private function persistAccountSnapshot(array $asset): void
    {
        $available = (float)($asset['available_balance'] ?? 0.0);
        $equity = (float)($asset['equity'] ?? $available);

        $account = $this->accounts->findOneByUserAndExchange($this->defaultUserId, $this->exchangeName);
        if ($account === null) {
            $account = (new UserExchangeAccount())
                ->setUserId($this->defaultUserId)
                ->setExchange($this->exchangeName);
        }

        $account
            ->setAvailableBalance($available)
            ->setBalance($equity)
            ->setLastBalanceSyncAt(new DateTimeImmutable());

        $this->accounts->save($account);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAssets(): array
    {
        if ($this->assetsCache !== null) {
            return $this->assetsCache;
        }

        $response = $this->assets->getAssetsDetail();
        $data = $response['data']['assets'] ?? $response['data'] ?? $response;
        $assets = is_array($data) ? array_filter($data, 'is_array') : [];

        return $this->assetsCache = $assets;
    }
}
