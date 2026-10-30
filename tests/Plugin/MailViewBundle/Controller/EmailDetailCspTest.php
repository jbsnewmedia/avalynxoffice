<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Plugin\MailViewBundle\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Body;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailBodyLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\BodyContentType;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

#[Group('integration')]
final class EmailDetailCspTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private const string CSP_STRICT = "default-src 'none'; img-src data: cid:; style-src 'unsafe-inline'; font-src data:";
    private const string CSP_RELAXED = "default-src 'none'; img-src data: cid: https:; style-src 'unsafe-inline'; font-src data: https:";

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private Account $account;
    private Folder $folder;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->refreshDatabase($this->entityManager);
        $this->seedBaseData();
        $this->client->loginUser($this->user);
    }

    public function testHtmlBodyIsRenderedInSandboxedIframeWithStrictCsp(): void
    {
        $email = $this->createHtmlEmail('<p>Hallo <img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt=""></p>');

        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();

        $iframe = $crawler->filter('iframe.email-html-frame');
        self::assertCount(1, $iframe);
        self::assertSame('allow-popups', $iframe->attr('sandbox'));
        self::assertSame('no-referrer', $iframe->attr('referrerpolicy'));

        $srcdoc = (string) $iframe->attr('srcdoc');
        self::assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="'.self::CSP_STRICT.'">', $srcdoc);
        self::assertStringContainsString('R0lGODlhAQABAAAAACw=', $srcdoc);

        self::assertCount(0, $crawler->filter('[data-action="load-external-content"]'));
        self::assertCount(0, $crawler->filter('.email-external-content-notice'));
    }

    public function testExternalImageReferenceShowsLoadExternalContentButton(): void
    {
        $email = $this->createHtmlEmail('<p>Tracking <img src="https://tracker.example.com/pixel.gif" width="1" height="1"></p>');

        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();

        $this->assertStrictCspPresent($crawler);

        $button = $crawler->filter('[data-action="load-external-content"]');
        self::assertCount(1, $button);
        self::assertSame(self::CSP_STRICT, $button->attr('data-csp-strict'));
        self::assertSame(self::CSP_RELAXED, $button->attr('data-csp-relaxed'));
        self::assertCount(1, $crawler->filter('.email-external-content-notice'));
    }

    public function testExternalCssUrlReferenceShowsLoadExternalContentButton(): void
    {
        $email = $this->createHtmlEmail('<div style="background: url( \'http://cdn.example.com/bg.png\' )">Inhalt</div>');

        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();

        $this->assertStrictCspPresent($crawler);
        self::assertCount(1, $crawler->filter('[data-action="load-external-content"]'));
    }

    public function testPlainLinkToExternalSiteDoesNotShowLoadExternalContentButton(): void
    {
        $email = $this->createHtmlEmail('<p>Mehr unter <a href="https://example.com/news">example.com</a></p>');

        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();

        $this->assertStrictCspPresent($crawler);
        self::assertCount(0, $crawler->filter('[data-action="load-external-content"]'));
    }

    public function testSrcsetReferenceShowsLoadExternalContentButton(): void
    {
        $email = $this->createHtmlEmail('<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" srcset="https://cdn.example.com/a.png 1x">');
        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-action="load-external-content"]'));
    }

    public function testProtocolRelativeReferenceShowsLoadExternalContentButton(): void
    {
        $email = $this->createHtmlEmail('<img src="//cdn.example.com/pixel.gif">');
        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-action="load-external-content"]'));
    }

    public function testCssImportShowsLoadExternalContentButton(): void
    {
        $email = $this->createHtmlEmail('<style>@import url("https://cdn.example.com/style.css");</style><p>Hi</p>');
        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-action="load-external-content"]'));
    }

    public function testActiveTagsAreStrippedFromSrcdoc(): void
    {
        $email = $this->createHtmlEmail(
            '<META HTTP-EQUIV="refresh" content="0;url=https://evil.example.com">'
            .'<base href="https://evil.example.com/">'
            .'<link rel="stylesheet" href="https://evil.example.com/x.css">'
            .'<script>alert(1)</script>'
            .'<p>Sichtbarer Inhalt</p>'
        );

        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();

        $srcdoc = (string) $crawler->filter('iframe.email-html-frame')->attr('srcdoc');
        self::assertStringContainsString('<p>Sichtbarer Inhalt</p>', $srcdoc);
        self::assertStringNotContainsStringIgnoringCase('http-equiv="refresh"', $srcdoc);
        self::assertStringNotContainsString('evil.example.com', $srcdoc);
        self::assertStringNotContainsString('<script', $srcdoc);
        self::assertStringNotContainsString('alert(1)', $srcdoc);
        // Only the <base target="_blank"> of the wrapper document remains.
        self::assertSame(1, substr_count($srcdoc, '<base '));
        self::assertStringContainsString('<base target="_blank">', $srcdoc);
        // The stripped <link>/<meta refresh> must not count as external content either.
        self::assertCount(0, $crawler->filter('[data-action="load-external-content"]'));
    }

    public function testTextOnlyEmailHasNoIframe(): void
    {
        $email = $this->createEmail();
        $this->attachBody($email, 'Nur Text mit https://example.com/pixel.gif', BodyContentType::TEXT);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', sprintf('/email/%s', $email->getId()));
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('iframe.email-html-frame'));
        self::assertCount(0, $crawler->filter('[data-action="load-external-content"]'));
    }

    private function assertStrictCspPresent(Crawler $crawler): void
    {
        $srcdoc = (string) $crawler->filter('iframe.email-html-frame')->attr('srcdoc');
        self::assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="'.self::CSP_STRICT.'">', $srcdoc);
    }

    private function createHtmlEmail(string $html): Email
    {
        $email = $this->createEmail();
        $this->attachBody($email, $html, BodyContentType::HTML);
        $this->entityManager->flush();

        return $email;
    }

    private function createEmail(): Email
    {
        $email = (new Email())
            ->setAccount($this->account)
            ->setSubject('CSP-Test')
            ->setPreviewBody('Vorschau')
            ->setReceivedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));

        $emailFolder = (new EmailFolder())
            ->setEmail($email)
            ->setFolder($this->folder);
        $email->addEmailFolder($emailFolder);

        $this->entityManager->persist($email);
        $this->entityManager->persist($emailFolder);

        return $email;
    }

    private function attachBody(Email $email, string $content, BodyContentType $type): void
    {
        $body = (new Body())->setContent($content);
        $link = (new EmailBodyLink())
            ->setEmail($email)
            ->setBody($body)
            ->setContentType($type);
        $email->addEmailBody($link);

        $this->entityManager->persist($body);
        $this->entityManager->persist($link);
    }

    private function seedBaseData(): void
    {
        $this->user = (new User())
            ->setEmail('reader@example.com')
            ->setPassword('secret');

        $this->account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');

        $this->folder = (new Folder())
            ->setName('Posteingang')
            ->setFunctions(['inbox']);
        $this->account->addFolder($this->folder);
        $this->user->addAccount($this->account);

        $this->entityManager->persist($this->user);
        $this->entityManager->persist($this->account);
        $this->entityManager->persist($this->folder);
        $this->entityManager->flush();
    }
}
