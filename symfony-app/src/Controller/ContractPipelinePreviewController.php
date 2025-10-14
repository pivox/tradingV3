<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Pipeline\MtfPipelineViewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class ContractPipelinePreviewController extends AbstractController
{
    public function __construct(private readonly MtfPipelineViewService $pipelines)
    {
    }

    #[Route('/pipelines/with-klines', name: 'pipelines_with_klines', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $preview = $this->pipelines->preview();
        return $this->json($preview);
    }
}
