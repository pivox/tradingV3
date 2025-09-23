<?php
// src/Controller/TimeController.php
namespace App\Controller;

use App\Util\TimeframeHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class TimeController extends AbstractController
{
    #[Route('/api/time/{timeframe}', name: 'api_time_aligned', methods: ['GET'])]
    public function aligned(Request $request, string $timeframe): JsonResponse
    {
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($timeframe);
            $open = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes, $now);

            // Optionnel: calcul de la "close" théorique du bloc courant
            $close = $open->modify("+{$tfMinutes} minutes");

            return new JsonResponse([
                'status' => 'ok',
                'timeframe' => $timeframe,
                'timeframe_minutes' => $tfMinutes,
                'now_utc_iso' => $now->format(\DateTimeInterface::RFC3339_EXTENDED),
                'now_utc' => $now->format(\DateTimeInterface::RSS),
                'aligned_open' => $open->format(\DateTimeInterface::RSS),
                'aligned_open_iso' => $open->format(\DateTimeInterface::RFC3339_EXTENDED),
                'aligned_open_unix' => $open->getTimestamp(),
                'aligned_close_iso' => $close->format(\DateTimeInterface::RFC3339_EXTENDED),
                'aligned_close' => $close->format(\DateTimeInterface::RSS),
                'aligned_close_unix' => $close->getTimestamp(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
                'hint' => 'Formats valides: 1m, 5m, 15m, 1h, 4h, 1d, 1w'
            ], 400);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Unexpected error',
            ], 500);
        }
    }
}
