<?php
// src/Controller/TimeController.php
namespace App\Controller;

use App\Util\TimeframeHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    #[Route('/api/bitmart/time', name: 'api_bitmart_time', methods: ['GET'])]
    public function bitmartTime(HttpClientInterface $http): JsonResponse
    {
        try {
            $resp = $http->request('GET', 'https://api-cloud.bitmart.com/system/time', [
                'timeout' => 5.0,
            ]);

            $status  = $resp->getStatusCode();
            $payload = json_decode($resp->getContent(false), true);

            if ($status !== 200 || !is_array($payload) || ($payload['code'] ?? null) !== 1000) {
                return $this->json([
                    'status'      => 'error',
                    'http_status' => $status,
                    'payload'     => $payload,
                    'message'     => 'BitMart a répondu avec un statut ou un format inattendu',
                ], 502);
            }

            $serverMs = (int) ($payload['data']['server_time'] ?? 0);
            if ($serverMs <= 0) {
                return $this->json([
                    'status'      => 'error',
                    'http_status' => $status,
                    'payload'     => $payload,
                    'message'     => 'Champ data.server_time manquant',
                ], 502);
            }

            $server = (new \DateTimeImmutable('@' . intdiv($serverMs, 1000)))
                ->setTimezone(new \DateTimeZone('UTC'));
            $now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $nowMs = (int) round((float) $now->format('U.u') * 1000);

            $deltaMs     = $serverMs - $nowMs;
            $thresholdMs = 60_000; // tolérance recommandée par BitMart : 1 minute

            $headers = $resp->getHeaders(false);
            $rate = [
                'mode'      => $headers['x-bm-ratelimit-mode'][0]      ?? null,
                'limit'     => $headers['x-bm-ratelimit-limit'][0]     ?? null,
                'remaining' => $headers['x-bm-ratelimit-remaining'][0] ?? null,
                'reset'     => $headers['x-bm-ratelimit-reset'][0]     ?? null,
            ];

            return $this->json([
                'status'      => 'ok',
                'http_status' => $status,
                'bitmart'     => [
                    'server_time_unix_ms' => $serverMs,
                    'server_time_iso'     => $server->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'rate_limit'          => $rate,
                ],
                'local'       => [
                    'now_unix_ms' => $nowMs,
                    'now_iso'     => $now->format(\DateTimeInterface::RFC3339_EXTENDED),
                ],
                'clock'       => [
                    'delta_ms'     => $deltaMs,
                    'in_sync'      => abs($deltaMs) <= $thresholdMs,
                    'tolerance_ms' => $thresholdMs,
                ],
            ]);
        } catch (TransportExceptionInterface $e) {
            return $this->json([
                'status'  => 'error',
                'message' => 'Transport error while calling BitMart',
                'detail'  => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            return $this->json([
                'status'  => 'error',
                'message' => 'Unexpected error',
                'detail'  => $e->getMessage(),
            ], 500);
        }
    }
}
