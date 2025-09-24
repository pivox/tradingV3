<?php
// src/Controller/BitmartBalanceController.php
declare(strict_types=1);

namespace App\Controller\Bitmart;


use App\Service\Account\Bitmart\BitmartBalanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class BitmartBalanceController extends AbstractController
{
    public function __construct(private readonly BitmartBalanceService $svc) {}

    #[Route('/api/bitmart/balance/spot', name: 'bitmart_spot_balance', methods: ['GET'])]
    public function spot(Request $req): Response
    {
        $currency = $req->query->get('currency'); // ex: USDT
        $needUsd  = filter_var($req->query->get('needUsdValuation', 'true'), FILTER_VALIDATE_BOOL);

        // /account/v1/wallet → solde par devise (avec valuation USD optionnelle)
        $account = $this->svc->getAccountBalance($currency ?: null, $needUsd);

        // /spot/v1/wallet → toutes les devises du wallet Spot (disponible + gelé)
        $wallet  = $this->svc->getSpotWallet();

        return $this->json([
            'account' => $account, // champs utiles: available, unAvailable, available_usd_valuation, etc.
            'wallet'  => $wallet,  // liste des devises avec available/frozen
            'fyi'     => 'account=solde par devise; wallet=liste complète',
        ]);
    }

    #[Route('/api/bitmart/balance/futures', name: 'bitmart_futures_balance', methods: ['GET'])]
    public function futures(): Response
    {
        $assets = $this->svc->getFuturesAssets(); // détails des actifs du compte Futures
        return $this->json([
            'futures_assets' => $assets,
        ]);
    }
}
