<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Repository\AppSettingRepository;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Manages the editable legal page content (Imprint, Privacy Policy, Terms)
 * stored as key/value settings rows in the app_setting table. Content is
 * passed through the "legal" HTML sanitizer before it is stored (and again
 * when rendered) so administrator input can never inject scripts.
 */
final readonly class LegalContentService
{
    public const string PAGE_IMPRINT = 'impressum';
    public const string PAGE_PRIVACY = 'datenschutz';
    public const string PAGE_TERMS = 'agb';

    public const string SETTING_KEY_PREFIX = 'legal_page_';

    /**
     * @var list<string>
     */
    public const array PAGES = [self::PAGE_IMPRINT, self::PAGE_PRIVACY, self::PAGE_TERMS];

    public function __construct(
        private AppSettingRepository $appSettingRepository,
        private HtmlSanitizerInterface $legalSanitizer,
    ) {
    }

    /**
     * Returns the app_setting key for a legal page.
     *
     * @param non-empty-string $page
     */
    public function settingKey(string $page): string
    {
        return self::SETTING_KEY_PREFIX.$page;
    }

    /**
     * Returns the stored HTML content of a legal page, or null when no
     * content has been saved yet.
     *
     * @param non-empty-string $page
     */
    public function getContent(string $page): ?string
    {
        return $this->appSettingRepository->getValue($this->settingKey($page));
    }

    /**
     * Stores the HTML content of a legal page after sanitizing it.
     *
     * @param non-empty-string $page
     */
    public function setContent(string $page, string $content): void
    {
        $this->appSettingRepository->setValue($this->settingKey($page), $this->sanitize($content));
    }

    /**
     * Removes everything but safe standard elements and links (no scripts,
     * styles or event handler attributes) from the given HTML.
     */
    public function sanitize(string $content): string
    {
        return $this->legalSanitizer->sanitize($content);
    }
}
