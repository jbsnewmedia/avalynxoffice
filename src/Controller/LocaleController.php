<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/locale/{_locale}', name: 'app_set_locale', requirements: ['_locale' => 'en|de'])]
    public function setLocale(Request $request, string $_locale): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->set('_locale', $_locale);
        }
        $target = $request->query->get('r');
        if (!\is_string($target) || '' === $target) {
            $target = $request->headers->get('referer') ?? '/';
        }

        return new RedirectResponse($target);
    }
}
