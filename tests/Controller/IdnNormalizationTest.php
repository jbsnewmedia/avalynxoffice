<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * IDN handling in the admin forms: Unicode input ("bästoff.de") is stored in
 * the canonical punycode form ("xn--bstoff-bua.de") and the admin tables
 * display the stored values decoded again.
 */
#[Group('integration')]
#[Group('functional')]
final class IdnNormalizationTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    public function testConnectorCreateStoresUnicodeDomainAsPunycode(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/connectors/new', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="connector[_token]"]')->attr('value');
        \assert(is_string($token));

        $this->client->request('POST', '/admin/connectors/new', [
            'connector' => [
                'name' => 'Test Connector',
                'domain' => 'bästoff.de',
                'plugin' => 'pop3',
                '_token' => $token,
            ],
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);

        $connector = $this->em->getRepository(Connector::class)->findOneBy(['domain' => 'xn--bstoff-bua.de']);
        self::assertNotNull($connector, 'The unicode domain must be stored in its punycode form.');
    }

    public function testConnectorDataEndpointDisplaysDecodedDomain(): void
    {
        $connector = (new Connector())
            ->setName('IDN Connector')
            ->setDomain('xn--bstoff-bua.de');
        $this->em->persist($connector);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/connectors/data', ['_token' => $this->setCsrfToken('datatable')]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertStringContainsString('bästoff.de', (string) $payload['data'][0]['data']['domain']);
        self::assertStringNotContainsString('xn--', (string) $payload['data'][0]['data']['domain']);
    }

    public function testAdminUserCreateStoresUnicodeEmailAsPunycode(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/users/new', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="user_admin[_token]"]')->attr('value');
        \assert(is_string($token));

        $this->client->request('POST', '/admin/users/new', [
            'user_admin' => [
                'email' => 'jbo@bästoff.de',
                'password' => 'test-password',
                'active' => '1',
                '_token' => $token,
            ],
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'jbo@xn--bstoff-bua.de']);
        self::assertNotNull($user, 'The unicode email must be stored in its punycode form.');
    }

    public function testUsersDataEndpointDisplaysDecodedEmail(): void
    {
        $user = new User();
        $user->setEmail('jbo@xn--bstoff-bua.de');
        $user->setPassword('test-password');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/data', ['_token' => $this->setCsrfToken('datatable')]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $emails = array_column(array_column($payload['data'], 'data'), 'email');
        self::assertContains('jbo@bästoff.de', $emails);

        // Role badges are sorted a-z by their translated label (the test
        // client runs in the default English locale).
        $emails = array_column(array_column($payload['data'], 'data'), 'email');
        $targetEmail = array_search('jbo@bästoff.de', $emails, true);
        self::assertNotFalse($targetEmail);
        $rolesRow = $payload['data'][$targetEmail]['data']['roles'];
        self::assertStringContainsString('Administrator', $rolesRow);
        self::assertStringContainsString('User', $rolesRow);
        self::assertLessThan(
            mb_strpos($rolesRow, 'User'),
            mb_strpos($rolesRow, 'Administrator'),
            'The Administrator badge must come before the User badge (a-z).',
        );
    }

    public function testProfileAccountPageDisplaysDecodedEmail(): void
    {
        $user = new User();
        $user->setEmail('jbo@xn--bstoff-bua.de');
        $user->setPassword('test-password');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile/account');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('jbo@bästoff.de', $content);
        self::assertStringNotContainsString('xn--bstoff-bua.de', $content);
    }

    public function testAccountCreateStoresUnicodeEmailAsPunycodeAndDecodesIt(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/accounts/new', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="account[_token]"]')->attr('value');
        \assert(is_string($token));

        $this->client->request('POST', '/admin/accounts/new', [
            'account' => [
                'name' => 'Info Konto',
                'email' => 'info@bästoff.de',
                '_token' => $token,
            ],
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success'], (string) $this->client->getResponse()->getContent());

        $account = $this->em->getRepository(Account::class)->findOneBy(['email' => 'info@xn--bstoff-bua.de']);
        self::assertNotNull($account, 'The unicode account email must be stored in its punycode form.');

        // The admin account table shows the stored address decoded again.
        $this->client->request('POST', '/admin/accounts/data', ['_token' => $this->setCsrfToken('datatable')]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $emails = array_column(array_column($payload['data'], 'data'), 'email');
        self::assertContains('info@bästoff.de', $emails);
    }

    /**
     * Stores a CSRF token directly in the client session and returns it.
     * The value is then accepted for the given token id.
     */
    private function setCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/login');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }
}
