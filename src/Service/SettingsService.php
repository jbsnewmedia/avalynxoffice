<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class SettingsService
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function isNewsletterOptIn(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->hasSession()) {
            return (bool) $request->getSession()->get('newsletter_optin', false);
        }

        return false;
    }

    public function setNewsletterOptIn(bool $optIn): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->hasSession()) {
            $request->getSession()->set('newsletter_optin', $optIn);
        }
    }
}
