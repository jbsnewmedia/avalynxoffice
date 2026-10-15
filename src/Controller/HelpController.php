<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HelpController extends AbstractController
{
    #[Route('/help', name: 'app_help')]
    public function index(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

        return $this->render('help/_modal_content.html.twig');
    }
}
