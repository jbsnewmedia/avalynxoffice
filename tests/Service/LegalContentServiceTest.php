<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Entity\AppSetting;
use AvalynxOffice\Service\LegalContentService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class LegalContentServiceTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
    }

    public function testSettingKeyUsesDedicatedPrefix(): void
    {
        $service = $this->createService();

        self::assertSame('legal_page_impressum', $service->settingKey(LegalContentService::PAGE_IMPRINT));
        self::assertSame('legal_page_datenschutz', $service->settingKey(LegalContentService::PAGE_PRIVACY));
        self::assertSame('legal_page_agb', $service->settingKey(LegalContentService::PAGE_TERMS));
    }

    public function testGetContentReturnsNullBeforeFirstSave(): void
    {
        $service = $this->createService();

        foreach (LegalContentService::PAGES as $page) {
            self::assertNull($service->getContent($page));
        }
    }

    public function testSetContentStoresAndUpdatesValue(): void
    {
        $service = $this->createService();

        $service->setContent(LegalContentService::PAGE_PRIVACY, '<p>Version 1</p>');
        self::assertSame('<p>Version 1</p>', $service->getContent(LegalContentService::PAGE_PRIVACY));

        $repository = $this->em->getRepository(AppSetting::class);
        self::assertSame(
            'legal_page_datenschutz',
            $repository->findOneBy(['settingValue' => '<p>Version 1</p>'])?->getSettingKey(),
        );

        $service->setContent(LegalContentService::PAGE_PRIVACY, '<p>Version 2</p>');
        self::assertSame('<p>Version 2</p>', $service->getContent(LegalContentService::PAGE_PRIVACY));
        self::assertSame(1, count($repository->findBy(['settingKey' => 'legal_page_datenschutz'])));
    }

    public function testPagesRemainIndependent(): void
    {
        $service = $this->createService();

        $service->setContent(LegalContentService::PAGE_IMPRINT, '<p>Imprint</p>');
        $service->setContent(LegalContentService::PAGE_TERMS, '<p>Terms</p>');

        self::assertSame('<p>Imprint</p>', $service->getContent(LegalContentService::PAGE_IMPRINT));
        self::assertSame('<p>Terms</p>', $service->getContent(LegalContentService::PAGE_TERMS));
        self::assertNull($service->getContent(LegalContentService::PAGE_PRIVACY));
    }

    public function testSetContentStripsScriptsStylesAndEventHandlers(): void
    {
        $service = $this->createService();

        $service->setContent(
            LegalContentService::PAGE_IMPRINT,
            '<h2>Imprint</h2><script>alert(1)</script><style>body{display:none}</style>'
            .'<p onclick="alert(2)">Text <a href="javascript:alert(3)">evil</a> <a href="https://example.com">ok</a> <a href="mailto:info@example.com">mail</a></p>'
            .'<iframe src="https://evil.example"></iframe>',
        );

        $stored = $service->getContent(LegalContentService::PAGE_IMPRINT);
        self::assertIsString($stored);
        self::assertStringNotContainsString('<script', $stored);
        self::assertStringNotContainsString('alert(1)', $stored);
        self::assertStringNotContainsString('<style', $stored);
        self::assertStringNotContainsString('onclick', $stored);
        self::assertStringNotContainsString('javascript:', $stored);
        self::assertStringNotContainsString('<iframe', $stored);
        self::assertStringContainsString('<h2>Imprint</h2>', $stored);
        self::assertStringContainsString('href="https://example.com"', $stored);
        // The sanitizer entity-encodes "@" in attributes.
        self::assertStringContainsString('href="mailto:info@example.com"', html_entity_decode($stored));
    }

    public function testSanitizeKeepsStandardFormattingElements(): void
    {
        $service = $this->createService();

        $html = '<h3>Title</h3><p><strong>bold</strong> <em>italic</em></p><ul><li>one</li><li>two</li></ul>';

        self::assertSame($html, $service->sanitize($html));
    }

    private function createService(): LegalContentService
    {
        return static::getContainer()->get(LegalContentService::class);
    }
}
