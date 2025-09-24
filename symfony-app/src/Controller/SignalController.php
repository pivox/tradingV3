<?php

declare(strict_types=1);


namespace App\Controller;


use App\Service\Trading\PositionOpener;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;


final class SignalController
{
    public function __construct(private readonly PositionOpener $positionOpener, private readonly LoggerInterface $logger)
    {
    }


    #[Route('/signals/consume', name: 'signals_consume', methods: ['POST'])]
    public function consume(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];
        $symbol = (string)($payload['symbol'] ?? '');
        $timeframe = (string)($payload['timeframe'] ?? '');
        $isValid = (bool)($payload['is_valid'] ?? false);
        $signalsPayload = (array)($payload['signals'] ?? []);


        if ($isValid && $timeframe === '1m') {
            $finalSide = strtoupper((string)($signalsPayload['final']['signal'] ?? 'NONE'));
            if (in_array($finalSide, ['LONG', 'SHORT'], true)) {
                $res = $this->positionOpener->open(
                    symbol: $symbol,
                    finalSideUpper: $finalSide,
                    timeframe: $timeframe,
                    tfSignal: $signalsPayload[$timeframe] ?? []
                );
                return new JsonResponse(['ok' => true, 'placed' => $res], 200);
            }
        }
        return new JsonResponse(['ok' => false, 'reason' => 'Not actionable'], 200);
    }
}
