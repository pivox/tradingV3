<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller\Web;

use App\Config\MtfValidationConfigProvider;
use App\MtfValidator\Dashboard\MtfDashboardBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class MtfDashboardController extends AbstractController
{
    public function __construct(
        private readonly MtfValidationConfigProvider $configProvider,
        private readonly MtfDashboardBuilder $dashboardBuilder,
    ) {}

    #[Route('/mtf/dashboard', name: 'mtf_dashboard_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('MtfValidator/dashboard/index.html.twig');
    }

    #[Route('/mtf/dashboard/summary', name: 'mtf_dashboard_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $mode = $this->configProvider->getPrimaryMode() ?? 'regular';
        $config = $this->configProvider->getConfigForMode($mode);
        $cfg = $config->getConfig();

        $startFrom = $cfg['validation']['start_from_timeframe'] ?? '4h';

        $board = $this->dashboardBuilder->build($startFrom);

        return new JsonResponse([
            'mode' => $mode,
            'start_from_timeframe' => $startFrom,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'with_trades' => $board['with_trades'],
            'ready_no_trade' => $board['ready_no_trade'],
            'blocked_by_tf' => $board['blocked_by_tf'],
        ]);
    }
}
