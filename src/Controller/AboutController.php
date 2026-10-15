<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AppService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AboutController extends AbstractController
{
    #[Route('/about', name: 'app_about')]
    public function index(Request $request, AppService $appService): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }
        $version = $appService->getVersion();

        return $this->render('about/_modal_content.html.twig', [
            'version' => $version,
            'year' => (int) date('Y'),
        ]);
    }
}
