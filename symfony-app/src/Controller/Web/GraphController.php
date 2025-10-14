<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GraphController extends AbstractController
{
    #[Route('/graph', name: 'graph_index')]
    public function index(): Response
    {
        return $this->render('graph/index.html.twig');
    }
}
