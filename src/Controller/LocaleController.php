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
        if (!\is_string($target) || !$this->isSafeRelativeTarget($target)) {
            $target = $this->sameHostRefererPath($request);
        }

        return new RedirectResponse($target);
    }

    private function sameHostRefererPath(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if (!\is_string($referer) || '' === $referer) {
            return '/';
        }

        $parts = parse_url($referer);
        if (false === $parts || !isset($parts['host'])
            || strtolower($parts['host']) !== strtolower($request->getHost())
            || (isset($parts['scheme']) && strtolower($parts['scheme']) !== strtolower($request->getScheme()))
            || (isset($parts['port']) && $parts['port'] !== $request->getPort())
        ) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if (!$this->isSafeRelativeTarget($path)) {
            return '/';
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function isSafeRelativeTarget(string $target): bool
    {
        return str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_contains($target, '\\')
            && 1 !== preg_match('/[\r\n]/', $target);
    }
}
