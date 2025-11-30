<?php

namespace App\Controller;

use App\Contract\Provider\AccountProviderInterface;
use App\Provider\Bitmart\BitmartOrderProvider;
use App\Repository\OrderIntentRepository;
use App\Service\OrderIntentManager;
use App\Service\TradeCycleRebuilder;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TradePositionController extends AbstractController
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly TradeCycleRebuilder $cycleRebuilder,
        private readonly OrderIntentRepository $orderIntentRepository,
        private readonly OrderIntentManager $orderIntentManager,
        private readonly BitmartOrderProvider $bitmartOrderProvider,
    )
    {

    }


    #[Route('/trade/orders', name: 'app_trade_orders')]
    public function index(AccountProviderInterface $accountProvider): JsonResponse
    {
        // 1) Interval
        $endTime = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $startTime = $endTime->setTime(hour: 0, minute: 0, second: 0)
            ->modify('-1 days')
            ->getTimestamp();

        // 2) Trades bruts depuis Bitmart
        $trades = $accountProvider->getTrades(
            startTime: $startTime,
            endTime: $endTime->getTimestamp()
        );
        foreach ($trades as $key => $trade) {
            $intent = $this->orderIntentManager->findIntent(orderId:  $trade['order_id']);
            if ($intent !== null) {
                $trades[$key]['order_intent'] = [
                    'id' => $intent->getId(),
                    'symbol' => $intent->getSymbol(),
                    'side' => $intent->getSide(),
                    'type' => $intent->getType(),
                    'size' => $intent->getSize(),
                    'price' => $intent->getPrice(),
                ];
            }
            $trades[$key]['order_history'] = $this->bitmartOrderProvider->getOrderHistory($trade['symbol'], 20);sleep(1);
            $trades[$key]['create_time'] = (new \DateTimeImmutable())->setTimestamp(intval($trade['create_time']/1000))->format('Y-m-d H:i:s');
        }

        //dd($trades);
     //   $positions = $accountProvider->getPositions();


        // 3) On reconstruit les cycles
        $cycles = $this->cycleRebuilder->buildCycles($trades);

        // 4) Sortie JSON
        return $this->json([
            'start' => $startTime,
            'end'   => $endTime->getTimestamp(),
            'cycles' => $cycles,
        ]);
    }
}
