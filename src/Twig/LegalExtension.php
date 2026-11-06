<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Twig;

use AvalynxOffice\Service\LegalContentService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the editable legal page content (impressum/datenschutz/agb) to
 * Twig so the shared legal modals — in the guest footer and in the base
 * layout — can render it without every controller passing it along.
 */
final class LegalExtension extends AbstractExtension
{
    public function __construct(
        private readonly LegalContentService $legalContentService,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('legal_pages', $this->legalPages(...)),
        ];
    }

    /**
     * @return array<string, string> page key => stored raw content ('' when unset)
     */
    private function legalPages(): array
    {
        $pages = [];
        foreach (LegalContentService::PAGES as $page) {
            $pages[$page] = $this->legalContentService->getContent($page) ?? '';
        }

        return $pages;
    }
}
