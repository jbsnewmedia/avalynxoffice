<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\LegalContentService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('functional')]
final class LegalControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
    }

    public function testAdminEditorRendersFullPageForAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/legal');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-legal-editor', $content);
        self::assertStringContainsString('summernote-next.min.js', $content);
    }

    public function testAdminEditorRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/settings/legal', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminEditorRendersEditorForAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/legal', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-legal-editor', $content);
        self::assertStringContainsString('data-legal-tab="impressum"', $content);
        self::assertStringContainsString('data-legal-tab="datenschutz"', $content);
        self::assertStringContainsString('data-legal-tab="agb"', $content);
        self::assertStringContainsString('template data-legal-content="impressum"', $content);
    }

    public function testAdminEditorShowsStoredContent(): void
    {
        $this->legalService()->setContent(LegalContentService::PAGE_IMPRINT, '<p>Mein Impressum</p>');

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/legal', [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<p>Mein Impressum</p>', $content);
    }

    public function testSaveRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->setCsrfToken('save_legal');
        $this->client->request('POST', '/settings/legal/save', [
            '_token' => $token,
            'page' => LegalContentService::PAGE_IMPRINT,
            'content' => '<p>Injected</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->legalService()->getContent(LegalContentService::PAGE_IMPRINT));
    }

    public function testSaveRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/settings/legal/save', [
            'page' => LegalContentService::PAGE_IMPRINT,
            'content' => '<p>No token</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->legalService()->getContent(LegalContentService::PAGE_IMPRINT));
    }

    public function testSaveRejectsUnknownPage(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('save_legal');
        $this->client->request('POST', '/settings/legal/save', [
            '_token' => $token,
            'page' => 'impressum-invalid',
            'content' => '<p>Unknown</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSaveStoresContent(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('save_legal');
        $this->client->request('POST', '/settings/legal/save', [
            '_token' => $token,
            'page' => LegalContentService::PAGE_TERMS,
            'content' => '<h2>AGB</h2><p>Paragraph 1</p>',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);
        self::assertSame('<h2>AGB</h2><p>Paragraph 1</p>', $this->legalService()->getContent(LegalContentService::PAGE_TERMS));
    }

    public function testPublicImpressumRendersStoredContentForGuests(): void
    {
        $this->legalService()->setContent(LegalContentService::PAGE_IMPRINT, '<p>JBS New Media GmbH</p>');

        $this->client->request('GET', '/impressum');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<p>JBS New Media GmbH</p>', $content);
    }

    public function testPublicPrivacyRendersStoredContentForGuests(): void
    {
        $this->legalService()->setContent(LegalContentService::PAGE_PRIVACY, '<p>Datenschutz</p>');

        $this->client->request('GET', '/datenschutz');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<p>Datenschutz</p>', (string) $this->client->getResponse()->getContent());
    }

    public function testPublicTermsRendersStoredContentForGuests(): void
    {
        $this->legalService()->setContent(LegalContentService::PAGE_TERMS, '<p>AGB Text</p>');

        $this->client->request('GET', '/agb');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<p>AGB Text</p>', (string) $this->client->getResponse()->getContent());
    }

    public function testPublicPagesShowFallbackWithoutContent(): void
    {
        $this->client->request('GET', '/impressum');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This page is currently not available.', (string) $this->client->getResponse()->getContent());
    }

    private function seedUsers(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->admin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->user = new User();
        $this->user->setEmail('user@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'user'));
        $this->user->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->em->persist($this->admin);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function legalService(): LegalContentService
    {
        return static::getContainer()->get(LegalContentService::class);
    }

    private function setCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/mails');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }
}
