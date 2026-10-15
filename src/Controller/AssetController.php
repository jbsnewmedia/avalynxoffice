<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class AssetController extends AbstractController
{
    #[Route('/assets/svg/app-logo.svg', name: 'app_assets_logo_svg')]
    public function logo(): Response
    {
        $projectDirParam = $this->getParameter('kernel.project_dir');
        $projectDir = is_string($projectDirParam) ? $projectDirParam : '';
        $filePath = $projectDir.'/assets/svg/avalynx-icon.svg';
        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setSharedMaxAge(31536000);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'app-logo.svg'
        );

        return $response;
    }

    #[Route('/assets/png/app-logo.png', name: 'app_assets_logo_png')]
    public function logoPng(): Response
    {
        $projectDirParam = $this->getParameter('kernel.project_dir');
        $projectDir = is_string($projectDirParam) ? $projectDirParam : '';
        $filePath = $projectDir.'/assets/png/avalynx-icon.png';
        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'image/png');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setSharedMaxAge(31536000);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'app-logo.png'
        );

        return $response;
    }

    #[Route('/assets/svg/jbsnewmedia-logo.svg', name: 'app_assets_jbsnewmedia_logo_svg')]
    public function jbsnewmediaLogo(): Response
    {
        $projectDirParam = $this->getParameter('kernel.project_dir');
        $projectDir = is_string($projectDirParam) ? $projectDirParam : '';
        $filePath = $projectDir.'/assets/svg/jbsnewmedia-logo.svg';
        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setSharedMaxAge(31536000);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'jbsnewmedia-logo.svg'
        );

        return $response;
    }

    #[Route('/assets/svg/jbsnewmedia-symbol.svg', name: 'app_assets_jbsnewmedia_symbol_svg')]
    public function jbsnewmediaSymbol(): Response
    {
        $projectDirParam = $this->getParameter('kernel.project_dir');
        $projectDir = is_string($projectDirParam) ? $projectDirParam : '';
        $filePath = $projectDir.'/assets/svg/jbsnewmedia-symbol.svg';
        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setSharedMaxAge(31536000);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'jbsnewmedia-symbol.svg'
        );

        return $response;
    }
}
